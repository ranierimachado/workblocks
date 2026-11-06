<?php
namespace Tests\Feature;
use Tests\TestCase;
class ExampleTest extends TestCase {public function test_root_opens_app():void{$this->get('/')->assertRedirect('/app/');}}
