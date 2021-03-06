<?php

namespace omz13\k3honehome;

use Exception;
use Kirby\Cms\Language;
use Kirby\Cms\Page;

use const HONEHOME_CONFIGURATION_PREFIX;
use const LC_ALL;
use const LC_COLLATE;
use const LC_CTYPE;
use const LC_MESSAGES;
use const PHP_URL_PATH;

use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_push;
use function array_reduce;
use function array_values;
use function arsort;
use function assert;
use function count;
use function define;
use function explode;
use function header;
use function is_array;
use function is_string;
use function json_encode;
use function kirby;
use function parse_url;
use function strpos;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;

define( 'HONEHOME_CONFIGURATION_PREFIX', 'omz13.honehome' );

/*
 * In kirby 3.1.2 or below, kirby()->language()->locale() returns a string. Nice!
 * In kirby 3.1.3 or better, kirby()->language()->locale() returns a string or an array
 * This function tries its best to cope with either.
 * It is a bit of a fudge.
 */

function localeFromLang( ?Language $lang = null ) : string {
  if ( $lang == null ) {
    $lang = kirby()->language();
  }

  $l = $lang->locale();

  // kirby < 3.1.3 - locale is a string
  if ( is_string( $l ) ) {
    return $l;
  }
  if ( is_array( $l ) ) {
    // array implies kirby >= 3.1.3 where can be array of LC_WHATEVER values.
    foreach ( [ LC_ALL, LC_CTYPE, LC_COLLATE, LC_MESSAGES ] as $w ) {
      $s = $lang->locale( $w );
      if ( $s != null ) {
        assert( is_string( $s ) ); // to stop stan complaining
        return $s;
      }
    }
    // Fallback: return first value in the array, and hope its good enough.
    return array_values( $l )[ '0' ];
  }
  throw new Exception( "Getting locale from language borked " . json_encode( $lang->locale() ), 1 );
}//end localeFromLang()

/*
 * Convert a PHP locale code to an HTML language code (and strip any pesky .utf8 or .utf-8 affix)
*/
function localeToLangCode( string $locale ) : string {
  // Normalize
  $locale = strtolower( $locale );
  // Clean (.whatever[@whatever])
  $x = strrpos( $locale, '.', 0 );
  if ( $x != false ) {
    $locale = substr( $locale, 0, -$x );
  }
  // And clean a bit more (just in case @whatever)
  $y = strrpos( $locale, '@', 0 );
  if ( $y != false ) {
    $locale = substr( $locale, 0, -$y );
  }
  // Huzzah! $locale is now sanitized (which is not the same as canonicalization)
  $x = explode( '_', $locale );

  if ( count( $x ) == 1 ) {
    return $x[0];
  }
  return $x[0] . '-' . strtoupper( $x [1] );
}//end localeToLangCode()

/**
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
//phpcs:disable Generic.Metrics.CyclomaticComplexity
function honehome() : Page {
  $debug = kirby()->option( 'debug' );

  $o = kirby()->option( HONEHOME_CONFIGURATION_PREFIX . '.disable' );
  if ( $o != null && $o == 'true' ) {
    if ( $debug == true ) {
      header( "X-omz13-hh: DISABLED" );
    }
    return kirby()->site()->homePage();
  }

  $home = null; // reset

  $n = kirby()->option( HONEHOME_CONFIGURATION_PREFIX . '.homelanding' );
  if ( $n != null && $n != '' ) {
    if ( $debug == true ) {
      header( "X-omz13-hh-from-c: " . $n );
    }
    $home = kirby()->site()->find( $n );
    assert( $home != null ); // throw configuration error
  }

  if ( $home == null ) {
    $n = kirby()->site()->content()->get( 'homelanding' )->toString();
    if ( $n != "" ) {
      if ( $debug == true ) {
        header( "X-omz13-hh-from-f: " . $n );
      }
      $home = kirby()->site()->find( $n );
      assert( $home != null );
    } else {
      $home = kirby()->site()->homePage();
    }
  }

  if ( $debug == true ) {
    header( "X-omz13-hh-landing:" . ( $n == "" ? 'homePage' : $n ) );
    header( "X-omz13-hh-multilang:" . ( kirby()->multilang() == false ? 's' : 'm' ) );
  }

  // Guard: if not multilanguage, all that's needed is the "homepage" now
  if ( kirby()->multilang() == false ) {
    return $home;
  }

  // Never say never
  // Test path not url, just in case there's a fragment or query
  assert( parse_url( $_SERVER['REQUEST_URI'] , PHP_URL_PATH ) == '/' );

  if ( $debug == true ) {
    header( "X-omz13-hh-Host:" . $_SERVER['HTTP_HOST'] );
  }

  // Guard: If request is to a langaue's configured url, already home, so just give it
  foreach ( kirby()->languages() as $lang ) {
    if ( $lang->url() ) {
      $want = $lang->url();

      $pos = strpos( $lang->url(), "//" );
      if ( $pos !== false ) {
        $want = substr( $lang->url(), $pos + 2 );
      }

      if ( $_SERVER['HTTP_HOST'] == $want ) {
        if ( $debug == true ) {
          header( "X-omz13-hh-Already:" . $lang->code() );
        }
        return $home;
      }
    }//end if
  }//end foreach

  if ( array_key_exists( 'HTTP_ACCEPT_LANGUAGE', $_SERVER ) ) {
  // en-US,en;q=0.8,en-GB;q=0.6,de;q=0.4,ja;q=0.2

    $prefLocales = array_reduce(
        explode( ',', strtolower( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ),
        function ( $res, $el ) {
          [$l, $q] = array_merge( explode( ';q=', $el ), [1] );
          $res[$l] = (float) $q;
          return $res;
        },
        []
    );
    arsort( $prefLocales );
    $wants = array_keys( $prefLocales );

    // A bit of OTT debug
    if ( $debug == true ) {
      header( "X-omz13-hh-Accept:" . json_encode( $wants ) );

      $a = [];
      foreach ( kirby()->languages() as $lang ) {
        array_push( $a, $lang->locale() );
      }
      header( "X-omz13-hh-HasLocales:" . json_encode( $a ) );
    }

    // Exact match
    foreach ( $wants as $want ) {
      foreach ( kirby()->languages() as $lang ) {
        $whatever = strtolower( localeToLangCode( localeFromLang( $lang ) ) );
        // header( "X-omz13-hh-match-try-e-" . $i++ . ":" . $want . " with " . $whatever );
        if ( $want == $whatever ) {
          if ( $debug == true ) {
            header( 'X-omz13-hh-Match:EXACT ' . $want . ' to (' . $lang->code() . ') ' . localeFromLang( $lang ) );
          }
          return kirby()->site()->visit( $home, $lang->code() );
//          kirby()->setCurrentLanguage( $lang->code() );
//          return $home;
//        go( $home->urlForLanguage( $lang->code() ), 302 );
//        return kirby()->site()->visit( $home, $lang->code() );
        }
      }
    }//end foreach

    // Best match if ignore any regional in request
    foreach ( $wants as $want ) {
      $wantwant = explode( '-', (string) $want );
      $want     = $wantwant[0];
      foreach ( kirby()->languages() as $lang ) {
        $whatever = strtolower( localeToLangCode( localeFromLang( $lang->locale() ) ) );
        // header( "X-omz13-hh-match-try-b-" . $i++ . ":" . $want . " with " . $whatever );
        if ( $want == $whatever ) {
          if ( $debug == true ) {
            header( 'X-omz13-hh-Match:BEST ' . $want . ' to (' . $lang->code() . ') ' . localeFromLang( $lang ) );
          }
          return kirby()->site()->visit( $home, $lang->code() );
//        go( $home->urlForLanguage( $lang->code() ), 302 );
        }
      }
    }//end foreach

    // Near match if ignore regional in both request and what's available
    foreach ( $wants as $want ) {
      $wantwant  = explode( '-', (string) $want );
      $shortwant = $wantwant[0];
      foreach ( kirby()->languages() as $lang ) {
        $whatever = explode( '-', localeToLangCode( localeFromLang( $lang->locale() ) ) )[0];
        // header( "X-omz13-hh-match-try-n-" . $i++ . ":" . $shortwant . " with " . $whatever );
        if ( $shortwant == $whatever ) {
          if ( $debug == true ) {
            header( 'X-omz13-hh-Match:NEAR ' . $want . ' to (' . $lang->code() . ') ' . localeFromLang( $lang ) );
          }
          return kirby()->site()->visit( $home, $lang->code() );
//        go( $home->urlForLanguage( $lang->code() ), 302 );
        }
      }
    }//end foreach

    // Fall through...
    if ( $debug == true ) {
      header( "X-omz13-hh-Match:FallThru" );
    }
  } else {
    if ( $debug == true ) {
      header( 'X-omz13-hh-Accept:MISSING' );
    }
  }//end if

  // If no preference requested, or nothing matched the preferene, just use the default language for the homepage
  if ( $debug == true ) {
    header( 'X-omz13-hh-DefaultTo:(' . kirby()->language()->code() . ') ' . localeFromLang() );
  }
  return kirby()->site()->visit( $home, kirby()->language()->code() );
//  kirby()->setCurrentLanguage( $lang->code() );
//  return $home;
//  go( $home->urlForLanguage( kirby()->language()->code() ), 302 );
}//end honehome()
