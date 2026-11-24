<?php

namespace App\Domain\Identity\Services;

use RuntimeException;

/** The message is an error code the frontend maps to copy (e.g. oauth_state_invalid). */
class SocialLoginFailed extends RuntimeException {}
