<?php

namespace WPElevator\Agent_Pilot_Vendor\WPElevator\Update_Client;

use RuntimeException;
class Update_Api_Exception extends RuntimeException
{
    public const INVALID_RESPONSE_DATA = 1001;
    // HTTP 200 response but invalid data in body.
    public const REQUEST_FAILED = 1002;
    // Transport error, timeout, 404.
}