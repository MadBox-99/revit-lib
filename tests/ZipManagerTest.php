<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class ZipManagerTest extends TestCase {

    private $tmp;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->tmp = sys_get_temp_dir() . '/crl-test-' . uniqid();
        mkdir( $this->tmp . '/source', 0777, true );
        mkdir( $this->tmp . '/zips', 0777, true );
        Functions\when( 'crl_source_dir' )->justReturn( $this->tmp . '/source' );
        Functions\when( 'crl_zips_dir' )->justReturn( $this->tmp . '/zips' );
        Functions\when( 'crl_zip_path' )->justReturn( $this->tmp . '/zips/revit-elemtar.zip' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'current_time' )->justReturn( '2026-05-19 12:00:00' );
    }

    protected function tearDown(): void {
        $this->rrmdir( $this->tmp );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function rrmdir( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        foreach ( scandir( $dir ) as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $path = $dir . '/' . $item;
            is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
        }
        rmdir( $dir );
    }

    public function test_list_source_files_returns_empty_array_when_dir_empty() {
        $mgr = new CRL_Zip_Manager();
        $this->assertSame( array(), $mgr->list_source_files() );
    }

    public function test_list_source_files_returns_files_with_metadata() {
        file_put_contents( $this->tmp . '/source/test.rfa', 'abc' );
        $mgr   = new CRL_Zip_Manager();
        $files = $mgr->list_source_files();
        $this->assertCount( 1, $files );
        $this->assertSame( 'test.rfa', $files[0]['name'] );
        $this->assertSame( 3, $files[0]['size'] );
    }

    public function test_list_source_files_excludes_dotfiles() {
        file_put_contents( $this->tmp . '/source/.htaccess', 'x' );
        file_put_contents( $this->tmp . '/source/visible.rfa', 'x' );
        $files = ( new CRL_Zip_Manager() )->list_source_files();
        $this->assertCount( 1, $files );
        $this->assertSame( 'visible.rfa', $files[0]['name'] );
    }

    public function test_regenerate_produces_zip_with_source_files() {
        file_put_contents( $this->tmp . '/source/a.rfa', 'aaa' );
        file_put_contents( $this->tmp . '/source/b.rfa', 'bbb' );
        $mgr = new CRL_Zip_Manager();
        $this->assertTrue( $mgr->regenerate() );
        $this->assertFileExists( $this->tmp . '/zips/revit-elemtar.zip' );

        $zip = new ZipArchive();
        $zip->open( $this->tmp . '/zips/revit-elemtar.zip' );
        $this->assertSame( 2, $zip->numFiles );
        $names = array();
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $names[] = $zip->getNameIndex( $i );
        }
        sort( $names );
        $this->assertSame( array( 'a.rfa', 'b.rfa' ), $names );
        $zip->close();
    }

    public function test_resolve_safe_path_rejects_traversal() {
        $mgr = new CRL_Zip_Manager();
        $safe = $mgr->resolve_safe_source_path( '../etc/passwd' );
        $this->assertFalse( $safe );
    }

    public function test_resolve_safe_path_accepts_filename() {
        file_put_contents( $this->tmp . '/source/legit.rfa', 'x' );
        $mgr = new CRL_Zip_Manager();
        $safe = $mgr->resolve_safe_source_path( 'legit.rfa' );
        $this->assertSame( realpath( $this->tmp . '/source/legit.rfa' ), $safe );
    }
}
