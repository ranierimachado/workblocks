<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Tests must never write to the user's live documents/ exchange.
        config(['workblocks.feed_enabled' => false]);
    }
}
