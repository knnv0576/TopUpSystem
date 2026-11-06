<?php

namespace App\DemoGame\Exceptions;

use RuntimeException;

/** 這筆扣點已被作廢，晚到的同鍵扣點不會生效。 */
class DebitVoided extends RuntimeException {}
