<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class TokensTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'current_time' )->alias( function( $type ) {
            return $type === 'mysql' ? gmdate( 'Y-m-d H:i:s' ) : time();
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_generate_returns_64_char_hex() {
        $token = CRL_Tokens::generate();
        $this->assertSame( 64, strlen( $token ) );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
    }

    public function test_two_generated_tokens_differ() {
        $this->assertNotSame( CRL_Tokens::generate(), CRL_Tokens::generate() );
    }

    public function test_calculate_expiry_adds_days_to_now_utc() {
        $expiry = CRL_Tokens::calculate_expiry( 7, '2026-05-19 12:00:00' );
        $this->assertSame( '2026-05-26 12:00:00', $expiry );
    }

    public function test_is_expired_returns_true_when_past() {
        $this->assertTrue( CRL_Tokens::is_expired( '2026-05-18 11:59:59', '2026-05-19 12:00:00' ) );
    }

    public function test_is_expired_returns_false_when_future() {
        $this->assertFalse( CRL_Tokens::is_expired( '2026-05-20 12:00:00', '2026-05-19 12:00:00' ) );
    }

    public function test_is_expired_returns_true_when_equal() {
        $this->assertTrue( CRL_Tokens::is_expired( '2026-05-19 12:00:00', '2026-05-19 12:00:00' ) );
    }
}
