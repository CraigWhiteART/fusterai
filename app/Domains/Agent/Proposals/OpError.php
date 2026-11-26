<?php

namespace App\Domains\Agent\Proposals;

use RuntimeException;

/** One op does not apply (internal to OpsApplier). */
final class OpError extends RuntimeException {}
