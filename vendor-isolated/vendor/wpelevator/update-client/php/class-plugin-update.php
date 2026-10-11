<?php

namespace WPElevator\Agent_Pilot_Vendor\WPElevator\Update_Client;

use RuntimeException;
use WP_Error;
class Plugin_Update
{
    private string $api_url;
    private string $plugin_basename;
    private array $config;
    /**
     * Package download URLs that require the license key authorization header.
     *
     * @var string[]
     */
    private array $download_urls_with_auth = [];
    /**
     * Update resolved from the update server during this request.
     *
     * Both the update transient filter and the Update URI hostname filter ask for it,
     * so the lookup is kept to a single request. False once a lookup came back empty.
     *
     * @var object|false|null
     */
    private $update = null;
    public function __construct(string $plugin_basename, string $api_url, array $config = [])
    {
        $this->plugin_basename = $plugin_basename;
        $this->api_url = $api_url;
        $this->config = $config;
    }
    public function get_slug(): string
    {
        return dirname($this->plugin_basename);
    }
    public function get_api_url(): string
    {
        if (!wp_http_supports(['ssl'])) {
            return set_url_scheme($this->api_url, 'http');
        }
        return $this->api_url;
    }
    private function get_config(): array
    {
        $config_default = ['signing_key' => null, 'license_key' => null];
        return array_merge($config_default, $this->config);
    }
    private function get_config_value(string $key)
    {
        $config = $this->get_config();
        if (isset($config[$key])) {
            if (is_callable($config[$key])) {
                return call_user_func($config[$key]);
            }
            return $config[$key];
        }
        return null;
    }
    private function get_signing_key(): ?string
    {
        $signing_key = $this->get_config_value('signing_key');
        if (is_string($signing_key) && '' !== trim($signing_key)) {
            return trim($signing_key);
        }
        return null;
    }
    private function get_license_key(): ?string
    {
        $license_key = $this->get_config_value('license_key');
        if (is_string($license_key) && '' !== trim($license_key)) {
            return trim($license_key);
        }
        return null;
    }
    /**
     * The package of the plugin, verified against the configured signing key.
     *
     * Resolved when the update runs rather than when the hooks are registered, since
     * init() runs as early as plugins_loaded where the configuration filters of the
     * plugin being updated may not be in place yet.
     */
    private function get_signed_package(): ?Signed_Package
    {
        $signing_key = $this->get_signing_key();
        if ($signing_key) {
            return new Signed_Package($signing_key);
        }
        return null;
    }
    /**
     * The basic authorization header for authenticating the update requests.
     *
     * Uses the site hostname as the username to match the WordPress application
     * passwords convention, same as the Update Pilot plugin.
     */
    private function get_authorization_header(): ?string
    {
        $license_key = $this->get_license_key();
        if ($license_key) {
            $auth_pair = sprintf('%s:%s', wp_parse_url(home_url(), PHP_URL_HOST), $license_key);
            return sprintf('Basic %s', base64_encode($auth_pair));
        }
        return null;
    }
    public function init()
    {
        add_filter(sprintf('update_plugins_%s', wp_parse_url($this->api_url, PHP_URL_HOST)), [$this, 'update_by_hostname'], 10, 4);
        // Serve the plugin information modal from the same endpoint.
        add_filter('plugins_api', [$this, 'filter_plugins_api'], 10, 3);
        // Verify the package signature and track the update package download URLs.
        add_filter('upgrader_pre_download', [$this, 'filter_upgrader_pre_download'], 10, 4);
        // Add the authorization header when downloading the update package.
        add_filter('http_request_args', [$this, 'filter_http_request_add_auth_headers'], 10, 2);
    }
    /**
     * Require a valid package signature when downloading the update package for our plugin.
     *
     * WP core no longer requests the signature verification when downloading the
     * package, so the download is performed here instead to enforce it.
     *
     * @see Signed_Package::download()
     *
     * @param bool|string|WP_Error $pre        Whether to short-circuit the download.
     * @param string                $package    The package URL being downloaded.
     * @param \WP_Upgrader          $upgrader   The upgrader instance.
     * @param array                 $hook_extra Extra hook arguments including the plugin basename.
     * @return bool|string|WP_Error
     */
    public function filter_upgrader_pre_download($pre, $package, $upgrader, $hook_extra)
    {
        if (false !== $pre) {
            return $pre;
            // Another filter has short-circuited the download.
        }
        if (empty($hook_extra['plugin']) || $this->plugin_basename !== $hook_extra['plugin']) {
            return $pre;
            // Not the plugin we are responsible for.
        }
        if ($this->get_license_key()) {
            // Registered before the download starts so the auth header filter can match the URL.
            $this->download_urls_with_auth[] = $package;
        }
        $signed_package = $this->get_signed_package();
        if (!$signed_package) {
            return $pre;
            // No signing key configured so WP core can download the package.
        }
        if (!preg_match('!^(http|https|ftp)://!i', $package)) {
            return $pre;
            // Local package files are used as is by WP core.
        }
        if ($upgrader instanceof \WP_Upgrader && isset($upgrader->skin)) {
            $upgrader->skin->feedback('downloading_package', $package);
        }
        try {
            $file = $signed_package->download($package);
        } catch (RuntimeException $e) {
            return new WP_Error('package_signature_verification_failed', $e->getMessage());
        }
        // Reached only when the signature was verified since the download throws otherwise.
        if ($upgrader instanceof \WP_Upgrader && isset($upgrader->skin)) {
            $upgrader->skin->feedback(sprintf(
                /* translators: %s: Base64 encoded public signing key. */
                __('Verified the package signature with the signing key %s.'),
                '<span class="code pre">%s</span>'
            ), $signed_package->get_public_key());
        }
        return $file;
    }
    /**
     * Add the license key authorization header when downloading the update package
     * for our plugin.
     *
     * @param array  $args The HTTP request arguments.
     * @param string $url  The request URL.
     * @return array
     */
    public function filter_http_request_add_auth_headers(array $args, string $url): array
    {
        if (in_array($url, $this->download_urls_with_auth, true)) {
            $authorization = $this->get_authorization_header();
            if ($authorization && empty($args['headers']['Authorization'])) {
                // Do not override existing authorization headers.
                $args['headers']['Authorization'] = $authorization;
            }
        }
        return $args;
    }
    private function get_api_error(Update_Api_Exception $e): WP_Error
    {
        return new WP_Error(sprintf('update_client_api_error_%d', $e->getCode()), $e->getMessage());
    }
    /**
     * * @param array|false $update {
     *     The plugin update data with the latest details. Default false.
     *
     *     @type string $id           Optional. ID of the plugin for update purposes, should be a URI
     *                                specified in the `Update URI` header field.
     *     @type string $slug         Slug of the plugin.
     *     @type string $version      The version of the plugin.
     *     @type string $url          The URL for details of the plugin.
     *     @type string $package      Optional. The update ZIP for the plugin.
     *     @type string $tested       Optional. The version of WordPress the plugin is tested against.
     *     @type string $requires_php Optional. The version of PHP which the plugin requires.
     *     @type bool   $autoupdate   Optional. Whether the plugin should automatically update.
     *     @type array  $icons        Optional. Array of plugin icons.
     *     @type array  $banners      Optional. Array of plugin banners.
     *     @type array  $banners_rtl  Optional. Array of plugin RTL banners.
     *     @type array  $translations {
     *         Optional. List of translation updates for the plugin.
     *
     *         @type string $language   The language the translation update is for.
     *         @type string $version    The version of the plugin this translation is for.
     *                                  This is not the version of the language file.
     *         @type string $updated    The update timestamp of the translation file.
     *                                  Should be a date in the `YYYY-MM-DD HH:MM:SS` format.
     *         @type string $package    The ZIP location containing the translation update.
     *         @type string $autoupdate Whether the translation should be automatically installed.
     *     }
     * }
     */
    public function update_by_hostname($update, $plugin_data, $plugin_file, $locales)
    {
        if (empty($update) && $this->plugin_basename === $plugin_file) {
            try {
                return $this->get_update((array) $plugin_data, (array) $locales);
            } catch (Update_Api_Exception $e) {
                return $this->get_api_error($e);
            }
        }
        return $update;
    }
    /**
     * Serve the "View details" modal of our plugin from the update server.
     *
     * @param false|object|array $result The result object or array. Default false.
     * @param string             $action The type of information being requested.
     * @param object             $args   Plugin API arguments.
     * @return false|object|array
     */
    public function filter_plugins_api($result, $action, $args)
    {
        if (empty($result) && 'plugin_information' === $action && !empty($args->slug) && $this->get_slug() === $args->slug) {
            try {
                return $this->fetch_plugin_information($args);
            } catch (Update_Api_Exception $e) {
                return $this->get_api_error($e);
            }
        }
        return $result;
    }
    private function fetch_plugin_information(object $args): object
    {
        $request_args = ['timeout' => 15, 'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()];
        $authorization = $this->get_authorization_header();
        if ($authorization) {
            $request_args['headers'] = ['Authorization' => $authorization];
        }
        $response = wp_remote_get(add_query_arg(['action' => 'plugin_information', 'request' => (array) $args], $this->get_api_url()), $request_args);
        if (is_wp_error($response)) {
            throw new Update_Api_Exception($response->get_error_message(), Update_Api_Exception::REQUEST_FAILED);
        }
        $response_code = wp_remote_retrieve_response_code($response);
        if (200 > $response_code || 300 <= $response_code) {
            throw new Update_Api_Exception(sprintf('Plugin information request failed with HTTP %d', $response_code), Update_Api_Exception::REQUEST_FAILED);
        }
        // WP core expects the nested sections, banners and icons as arrays, like plugins_api() decodes them.
        $information = json_decode(wp_remote_retrieve_body($response), true);
        if (is_array($information) && !empty($information['slug'])) {
            return (object) $information;
        }
        throw new Update_Api_Exception(sprintf('Missing plugin information for %s', $args->slug), Update_Api_Exception::INVALID_RESPONSE_DATA);
    }
    private function get_plugin_data(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        return $plugins[$this->plugin_basename] ?? [];
    }
    /**
     * The update for our plugin, looked up once per request.
     *
     * @param array    $plugin_data Plugin headers of the installed plugin.
     * @param string[] $locales     Installed locales to look up translations for.
     */
    private function get_update(array $plugin_data, array $locales = []): ?object
    {
        if (!isset($this->update)) {
            $this->update = $this->fetch_update($plugin_data, $locales) ?? false;
        }
        if ($this->update) {
            return $this->update;
        }
        return null;
    }
    /**
     * Ask the update server for the update of our plugin.
     *
     * Posts the plugin headers the same way wp_update_plugins() does for the
     * WordPress.org API, which is the payload the update server expects, and reads
     * the update keyed by our plugin basename out of the response.
     *
     * @param array    $plugin_data Plugin headers of the installed plugin.
     * @param string[] $locales     Installed locales to look up translations for.
     *
     * @return object|null The update object for our plugin, or null if no update is available.
     *
     * @throws Update_Api_Exception If the update server request fails or returns invalid data.
     */
    private function fetch_update(array $plugin_data, array $locales): ?object
    {
        $payload = ['body' => ['plugins' => wp_json_encode([$this->plugin_basename => $plugin_data]), 'locale' => wp_json_encode(array_values($locales))]];
        $authorization = $this->get_authorization_header();
        if ($authorization) {
            $payload['headers'] = ['Authorization' => $authorization];
        }
        $response = wp_remote_post($this->get_api_url(), $payload);
        if (is_wp_error($response)) {
            throw new Update_Api_Exception($response->get_error_message(), Update_Api_Exception::REQUEST_FAILED);
        }
        $response_code = wp_remote_retrieve_response_code($response);
        if (200 > $response_code || 300 <= $response_code) {
            throw new Update_Api_Exception(sprintf('Update request failed with HTTP %d', $response_code), Update_Api_Exception::REQUEST_FAILED);
        }
        // WP core expects the nested icons, banners and translations as arrays, like wp_update_plugins() decodes them.
        $updates = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($updates)) {
            throw new Update_Api_Exception(sprintf('Invalid update data for %s', $this->plugin_basename), Update_Api_Exception::INVALID_RESPONSE_DATA);
        }
        if (empty($updates[$this->plugin_basename]) || !is_array($updates[$this->plugin_basename])) {
            throw new Update_Api_Exception(sprintf('Missing update data for %s', $this->plugin_basename), Update_Api_Exception::INVALID_RESPONSE_DATA);
        }
        return (object) $updates[$this->plugin_basename];
    }
}