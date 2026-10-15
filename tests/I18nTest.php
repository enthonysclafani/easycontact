<?php
/**
 * English source strings with the bundled Italian translation.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\Forms\Forms;
use EasyFormly\Forms\Renderer;
use EasyFormly\Forms\Schema;
use EasyFormly\Plugin;

/**
 * Italian sites keep today's interface; other sites read English (D9).
 */
class I18nTest extends TestCase {

	/**
	 * Forget the Italian strings, so the next test reads English again.
	 */
	public function tear_down(): void {
		unload_textdomain( 'easyformly' );
		parent::tear_down();
	}

	/**
	 * The bundled translation covers every source string, so nothing on an Italian site falls back to English.
	 */
	public function test_the_italian_translation_covers_every_string(): void {
		$pot = $this->entries( dirname( __DIR__ ) . '/languages/easyformly.pot' );
		$po  = $this->entries( dirname( __DIR__ ) . '/languages/easyformly-it_IT.po' );

		$this->assertSame( array_keys( $pot ), array_keys( $po ), 'The translation has exactly the strings of the template' );
		foreach ( $po as $msgid => $msgstr ) {
			$this->assertNotSame( '', $msgstr, 'Translated: ' . $msgid );
		}
		$this->assertFileExists( dirname( __DIR__ ) . '/languages/easyformly-it_IT.mo', 'The compiled translation ships with the plugin' );
	}

	/**
	 * An Italian site sees the same texts as before the switch to English sources.
	 */
	public function test_an_italian_site_keeps_its_texts(): void {
		// The test site has no Italian core files, so the locale is set directly; the plugin runs from outside
		// wp-content/plugins, so its translation file is found where the checkout keeps it.
		add_filter( 'locale', static fn() => 'it_IT' );
		add_filter( 'load_textdomain_mofile', static fn( $mofile, $domain ) => 'easyformly' === $domain ? EASYFORMLY_DIR . 'languages/' . basename( $mofile ) : $mofile, 10, 2 );
		Plugin::init();

		$this->assertSame( 'Impostazioni', __( 'Settings', 'easyformly' ) );
		$this->assertSame( 'Grazie, il messaggio è stato acquisito.', __( 'Thank you, your message has been received.', 'easyformly' ) );
		/* translators: 1: anti-spam score, 2: anti-spam level. */
		$this->assertSame( 'Punteggio: 40/100 · Livello 2', sprintf( __( 'Score: %1$d/100 · Level %2$d', 'easyformly' ), 40, 2 ) );

		$definition = $this->definition();
		$html       = Renderer::render( $this->form( $definition ), $definition );
		$this->assertStringContainsString( '>Invia</button>', $html, 'The form button' );
		$this->assertStringContainsString( 'placeholder="Cerca paese…"', $html, 'The country search' );

		$template = Schema::parse( Forms::default_template() );
		$this->assertNotWPError( $template, 'The translated default form is valid' );
		$this->assertSame( 'Desidero ricevere la newsletter', $template['fields']['newsletter']['label'] );
	}

	/**
	 * Other sites read the English sources, and the default form stays valid.
	 */
	public function test_other_sites_read_english(): void {
		$definition = $this->definition();
		$html       = Renderer::render( $this->form( $definition ), $definition );

		$this->assertStringContainsString( '>Send</button>', $html );
		$this->assertStringContainsString( 'placeholder="Search country…"', $html );
		$template = Schema::parse( Forms::default_template() );
		$this->assertNotWPError( $template );
		$this->assertSame( 'Name', $template['fields']['nome']['label'] );
	}

	/**
	 * Msgid and msgstr pairs of a PO or POT file, without the header.
	 *
	 * @param string $file File path.
	 * @return array<string, string>
	 */
	private function entries( string $file ): array {
		$this->assertFileExists( $file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the checkout.
		preg_match_all( '/^msgid ((?:".*"\n)+)msgstr ((?:".*"\n?)+)/m', (string) file_get_contents( $file ), $matches, PREG_SET_ORDER );
		$entries = array();
		foreach ( $matches as $match ) {
			$msgid = implode( '', array_map( 'stripcslashes', preg_match_all( '/"(.*)"/', $match[1], $parts ) ? $parts[1] : array() ) );
			if ( '' !== $msgid ) {
				$entries[ $msgid ] = implode( '', array_map( 'stripcslashes', preg_match_all( '/"(.*)"/', $match[2], $parts ) ? $parts[1] : array() ) );
			}
		}
		ksort( $entries );
		return $entries;
	}
}
