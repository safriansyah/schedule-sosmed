<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suite runs against the real database, where the demo accounts the
     * tests pick by role ("the first curator") get disabled or put on a login
     * schedule once real staff accounts exist. A test about content approval
     * must not fail because someone disabled "Curator Satu".
     *
     * So, inside the test's own transaction ONLY, every account is made
     * loginable again. The transaction is rolled back after the test, so the
     * live data is never touched — and a test that does not run inside a
     * transaction is left alone entirely.
     */
    protected function setUpTraits()
    {
        $uses = parent::setUpTraits();

        if (isset($uses[DatabaseTransactions::class])) {
            DB::table('users')->update(['is_active' => true, 'login_schedule_enabled' => false]);
        }

        return $uses;
    }
}
