<?php

namespace FLAIRUK\Starlink\Telemetry;

/**
 * The device type codes Starlink sends in the first column of every row.
 */
enum DeviceType: string
{
    case UserTerminal = 'u';
    case Router = 'r';
    case IpAllocs = 'i';
}
