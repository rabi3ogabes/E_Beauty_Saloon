<?php

namespace App\Domain\Insights\Ask;

use App\Support\Exceptions\DomainException;

/** A query plan that is not on the ask-your-data whitelist. */
class RejectedPlan extends DomainException {}
