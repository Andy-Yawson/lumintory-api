<?php

namespace App\Services\Pay;

/** Pay understood the request and said no. The message is written for people and is safe to show. */
class PayRejected extends \RuntimeException {}
