<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class RateLimiterTest extends TestCase {

    private $store = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->store = array();
        Functions\when( 'get_transient' )->alias( function( $key ) {
            return isset( $this->store[ $key ] ) ? $this->store[ $key ] : false;
        } );
        Functions\when( 'set_transient' )->alias( function( $key, $value, $ttl ) {
            $this->store[ $key ] = $value;
            return true;
        } );
        Functions\when( 'delete_transient' )->alias( function( $key ) {
            unset( $this->store[ $key ] );
            return true;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_allows_first_request() {
        $limiter = new CRL_Rate_Limiter();
        $this->assertTrue( $limiter->allow( '1.2.3.4', 3 ) );
    }

    public function test_allows_up_to_limit() {
        $limiter = new CRL_Rate_Limiter();
        $this->assertTrue( $limiter->allow( '1.2.3.4', 3 ) );
        $this->assertTrue( $limiter->allow( '1.2.3.4', 3 ) );
        $this->assertTrue( $limiter->allow( '1.2.3.4', 3 ) );
    }

    public function test_blocks_after_limit_reached() {
        $limiter = new CRL_Rate_Limiter();
        $limiter->allow( '1.2.3.4', 2 );
        $limiter->allow( '1.2.3.4', 2 );
        $this->assertFalse( $limiter->allow( '1.2.3.4', 2 ) );
    }

    public function test_separate_ips_have_separate_counters() {
        $limiter = new CRL_Rate_Limiter();
        $limiter->allow( '1.2.3.4', 1 );
        $this->assertTrue( $limiter->allow( '9.9.9.9', 1 ) );
    }

    public function test_empty_ip_is_blocked() {
        $limiter = new CRL_Rate_Limiter();
        $this->assertFalse( $limiter->allow( '', 3 ) );
    }
}
