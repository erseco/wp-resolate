<?php
/**
 * A PDF layout: the HTML file a document type is drawn from.
 *
 * A layout is an ordinary HTML file under `templates/pdf/`. Its `<head>` says
 * how the page furniture is drawn — letterhead, addresses, folio, crest,
 * margins, font — and its `<body>` carries the TinyButStrong tags the merger
 * fills in. This class reads the head; the writer draws the body.
 *
 * A document type points at one of these files through its
 * `documentate_type_pdf_layout` term meta, and `for_post()` is what turns a
 * document into the layout that renders it.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

/**
 * Reads a layout file and hands over its title and validated options.
 */
class Documentate_Pdf_Layout {

	/**
	 * Taxonomy whose terms are the document types.
	 */
	const TAXONOMY = 'documentate_doc_type';

	/**
	 * Term meta of `documentate_doc_type` that names the layout of a type.
	 */
	const META_KEY = 'documentate_type_pdf_layout';

	/** Marker for the one-time pass that gives existing types a layout. */
	const ASSIGNED_OPTION = 'documentate_pdf_layout_assigned';

	/** Term meta holding the office template a document type merges from. */
	const TEMPLATE_META = 'documentate_type_template_id';

	/**
	 * Layout titles already parsed, keyed by file path and modification time.
	 *
	 * @var array<string,string>
	 */
	private static $titles = array();

	/**
	 * Layout used by a document type that names none.
	 */
	const DEFAULT_SLUG = 'generic';

	/**
	 * Prefix every option-carrying `<meta name>` starts with.
	 */
	const META_PREFIX = 'documentate-';

	/**
	 * Document options a layout that sets nothing ends up with.
	 *
	 * The list is the one `Documentate_Pdf_Document` accepts, so `options()`
	 * can be handed to its constructor as it stands.
	 */
	const DEFAULTS = array(
		'letterhead'         => 'none',
		'addresses'          => 'none',
		'folio'              => 'none',
		'crest'              => false,
		'margins'            => array( 20.0, 20.0, 20.0, 20.0 ),
		'first_page_margins' => null,
		'font'               => 'times',
		'font_size'          => 11.0,
	);

	/**
	 * Letterheads the document knows how to draw.
	 */
	const LETTERHEADS = array( 'none', 'standard', 'large', 'resolution' );

	/**
	 * Places the document knows how to print the addresses in.
	 */
	const ADDRESSES = array( 'none', 'band', 'band-title', 'header', 'footer' );

	/**
	 * Places the document knows how to print the folio in.
	 */
	const FOLIOS = array( 'none', 'header', 'footer' );

	/**
	 * Core fonts the document can be typeset in.
	 */
	const FONTS = array( 'times', 'helvetica', 'courier' );

	/**
	 * Values a boolean meta is switched on by.
	 */
	const TRUTHY = array( '1', 'true', 'yes', 'on' );

	/**
	 * Absolute path of the layout file.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * File name without the `.html` extension.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Contents of the `<title>` element, empty when there is none.
	 *
	 * @var string
	 */
	private $title = '';

	/**
	 * Raw `documentate-*` meta values, keyed without the prefix.
	 *
	 * @var array<string,string>
	 */
	private $meta = array();

	/**
	 * Directory the shipped layouts live in.
	 *
	 * @return string Absolute path, with a trailing slash.
	 */
	public static function dir() {
		return DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/';
	}

	/**
	 * Every shipped layout, as slug => title, sorted by slug.
	 *
	 * This is the list the document-type screen offers in its layout select,
	 * so a layout that gives itself no `<title>` is labelled with its slug
	 * rather than with nothing at all.
	 *
	 * @return array<string,string>
	 */
	public static function available() {
		$files  = glob( self::dir() . '*.html' );
		$titles = array();

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$titles[ basename( $file, '.html' ) ] = self::cached_title( $file );
		}

		ksort( $titles, SORT_STRING );

		return $titles;
	}

	/**
	 * Title of a layout file, parsed once per version of that file.
	 *
	 * Reading the list is cheap; parsing every layout is not, and the document
	 * type screen asks for it two or three times per request. The key carries
	 * the modification time so a layout edited on disk is still picked up.
	 *
	 * @param string $file Absolute path of a layout file.
	 * @return string
	 */
	private static function cached_title( $file ) {
		$slug = basename( $file, '.html' );
		$key  = $file . ':' . (string) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A vanished file just misses the cache.

		if ( ! isset( self::$titles[ $key ] ) ) {
			$title                 = self::for_file( $file )->title();
			self::$titles[ $key ] = ( '' === $title ) ? $slug : $title;
		}

		return self::$titles[ $key ];
	}

	/**
	 * Layout the document type of a post asks for.
	 *
	 * Never fails: a post carrying no type, a type naming no layout and a type
	 * naming a layout that is not there all resolve to the generic layout, so
	 * every document has something to render on.
	 *
	 * @param int $post_id Document the layout is wanted for.
	 * @return self
	 */
	public static function for_post( $post_id ) {
		return self::for_file( self::dir() . self::slug_for_post( $post_id ) . '.html' );
	}

	/**
	 * Give every existing document type the layout its template stands for.
	 *
	 * A type created before this release carries no layout, and `for_post()`
	 * would fall back to `generic` — a bare label and value list instead of
	 * the institutional document. The office template a type merges from is
	 * named after the layout that reproduces it, so the assignment is a name
	 * match and nothing is guessed: a template that matches no shipped layout
	 * is left alone and keeps falling back.
	 *
	 * Runs once per site; a type saved afterwards is the administrator's.
	 */
	public static function assign_missing() {
		if ( get_option( self::ASSIGNED_OPTION ) ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		// A failed lookup leaves the marker unwritten so the pass runs again:
		// giving up for good would leave every existing type on the generic
		// layout with nothing to show for it.
		if ( is_wp_error( $terms ) ) {
			return;
		}

		$available = self::available();

		foreach ( $terms as $term_id ) {
			if ( '' !== (string) get_term_meta( (int) $term_id, self::META_KEY, true ) ) {
				continue;
			}

			$slug = self::slug_from_template( (int) $term_id );
			if ( '' !== $slug && array_key_exists( $slug, $available ) ) {
				update_term_meta( (int) $term_id, self::META_KEY, $slug );
			}
		}

		update_option( self::ASSIGNED_OPTION, '1', false );
	}

	/**
	 * Layout slug the office template of a document type is named after.
	 *
	 * WordPress appends `-1`, `-2` and so on when an upload collides with an
	 * existing file name, so a suffixed copy resolves to the same layout.
	 *
	 * @param int $term_id Document type.
	 * @return string Slug, or '' when the type has no usable template.
	 */
	private static function slug_from_template( $term_id ) {
		$attachment = (int) get_term_meta( $term_id, self::TEMPLATE_META, true );
		if ( $attachment <= 0 ) {
			return '';
		}

		$file = get_attached_file( $attachment );
		if ( ! is_string( $file ) || '' === $file ) {
			return '';
		}

		$name  = sanitize_key( pathinfo( $file, PATHINFO_FILENAME ) );
		$plain = (string) preg_replace( '/-\d+$/', '', $name );

		return array_key_exists( $name, self::available() ) ? $name : $plain;
	}

	/**
	 * Read a layout from a file.
	 *
	 * A file that does not exist yields a layout on the default options rather
	 * than an error, so a document type pointing at a deleted layout still
	 * renders.
	 *
	 * @param string $path Absolute path of the layout file.
	 * @return self
	 */
	public static function for_file( $path ) {
		return new self( (string) $path );
	}

	/**
	 * Absolute path of the layout file.
	 *
	 * @return string
	 */
	public function path() {
		return $this->path;
	}

	/**
	 * File name without the `.html` extension.
	 *
	 * @return string
	 */
	public function slug() {
		return $this->slug;
	}

	/**
	 * Name the layout gives itself, for the document type select.
	 *
	 * @return string
	 */
	public function title() {
		return $this->title;
	}

	/**
	 * Document options the layout asks for, validated and completed.
	 *
	 * Every value is checked against the closed list the document accepts, and
	 * anything else falls back to the default, so a mistyped layout renders on
	 * plain A4 instead of failing.
	 *
	 * @return array<string,mixed>
	 */
	public function options() {
		$options = self::DEFAULTS;

		$options['letterhead']         = $this->enum( 'letterhead', self::LETTERHEADS, $options['letterhead'] );
		$options['addresses']          = $this->enum( 'addresses', self::ADDRESSES, $options['addresses'] );
		$options['folio']              = $this->enum( 'folio', self::FOLIOS, $options['folio'] );
		$options['font']               = $this->enum( 'font', self::FONTS, $options['font'] );
		$options['crest']              = $this->flag( 'crest' );
		$options['margins']            = $this->margins( $options['margins'] );
		$options['font_size']          = $this->number( 'font-size', $options['font_size'] );
		$options['first_page_margins'] = $this->first_page_margins( $options['margins'] );

		return $options;
	}

	/**
	 * Absolute path of an image the layout is allowed to embed.
	 *
	 * Only a bare file name that exists under `templates/pdf/img` resolves:
	 * a layout must not be able to reach an arbitrary file on the server, so
	 * anything carrying a directory separator, and anything that resolves
	 * outside the image directory, is refused.
	 *
	 * @param string $src `src` attribute of an `<img>` in the layout.
	 * @return string Absolute path, or an empty string when the image is refused.
	 */
	public function image_path( $src ) {
		$src = (string) $src;
		if ( '' === $src || basename( $src ) !== $src ) {
			return '';
		}

		$directory = realpath( self::image_dir() );
		$file      = realpath( self::image_dir() . $src );

		if ( false === $directory || false === $file || 0 !== strpos( $file, $directory . DIRECTORY_SEPARATOR ) ) {
			return '';
		}

		return $file;
	}

	/**
	 * Keep the path and read the head of the file.
	 *
	 * @param string $path Absolute path of the layout file.
	 */
	private function __construct( $path ) {
		$this->path = $path;
		$this->slug = basename( $path, '.html' );

		$this->read_head();
	}

	/**
	 * Directory the layout images live in.
	 *
	 * @return string
	 */
	private static function image_dir() {
		return self::dir() . 'img/';
	}

	/**
	 * Layout slug the document type of a post names, or the default one.
	 *
	 * The stored name is untrusted input: an administrator types it, and a
	 * corrupt row can hold anything at all. It is first reduced to the
	 * characters a key may have, which both settles a hand-edited value and
	 * leaves nothing a path could be built out of — no separator, no dot, no
	 * null byte — and it then has to name one of the layouts actually
	 * shipped. The two checks overlap deliberately: either alone would confine
	 * the name as the code stands, so keeping both means a later change to one
	 * of them cannot open a hole by itself.
	 *
	 * @param int $post_id Document the layout is wanted for.
	 * @return string
	 */
	private static function slug_for_post( $post_id ) {
		$terms = wp_get_post_terms( (int) $post_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return self::DEFAULT_SLUG;
		}

		// A document carries a single type, so the first term is the one that counts.
		$stored = get_term_meta( (int) $terms[0], self::META_KEY, true );
		$slug   = is_string( $stored ) ? sanitize_key( $stored ) : '';

		return array_key_exists( $slug, self::available() ) ? $slug : self::DEFAULT_SLUG;
	}

	/**
	 * Read the title and the `documentate-*` metas out of the file.
	 */
	private function read_head() {
		$html = is_readable( $this->path ) ? (string) file_get_contents( $this->path ) : '';
		if ( '' === $html ) {
			return;
		}

		$dom      = new DOMDocument();
		$reported = libxml_use_internal_errors( true );
		$dom->loadHTML( $html, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $reported );

		$titles = $dom->getElementsByTagName( 'title' );
		if ( $titles->length > 0 ) {
			$this->title = trim( $titles->item( 0 )->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
		}

		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
			$name = strtolower( trim( $meta->getAttribute( 'name' ) ) );
			if ( 0 === strpos( $name, self::META_PREFIX ) ) {
				$this->meta[ substr( $name, strlen( self::META_PREFIX ) ) ] = trim( $meta->getAttribute( 'content' ) );
			}
		}
	}

	/**
	 * Value of a meta, or an empty string when the layout does not set it.
	 *
	 * @param string $name Meta name without the `documentate-` prefix.
	 * @return string
	 */
	private function raw( $name ) {
		return isset( $this->meta[ $name ] ) ? $this->meta[ $name ] : '';
	}

	/**
	 * A meta whose value has to belong to a closed list.
	 *
	 * @param string   $name    Meta name without the prefix.
	 * @param string[] $allowed Accepted values.
	 * @param string   $default Value used when the meta is missing or unknown.
	 * @return string
	 */
	private function enum( $name, array $allowed, $default ) {
		$value = strtolower( $this->raw( $name ) );

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * A meta that switches something on.
	 *
	 * @param string $name Meta name without the prefix.
	 * @return bool
	 */
	private function flag( $name ) {
		return in_array( strtolower( $this->raw( $name ) ), self::TRUTHY, true );
	}

	/**
	 * A meta holding one positive number.
	 *
	 * @param string $name    Meta name without the prefix.
	 * @param float  $default Value used when the meta is missing or not a positive number.
	 * @return float
	 */
	private function number( $name, $default ) {
		$value = $this->raw( $name );

		return ( is_numeric( $value ) && (float) $value > 0 ) ? (float) $value : (float) $default;
	}

	/**
	 * The `margins` meta as (top, right, bottom, left) in millimetres.
	 *
	 * @param array<int,float> $default Value used when the meta is missing or malformed.
	 * @return array<int,float>
	 */
	private function margins( array $default ) {
		$margins = $this->four_numbers( 'margins' );

		return ( null === $margins ) ? $default : $margins;
	}

	/**
	 * A meta holding four numbers separated by spaces.
	 *
	 * @param string $name Meta name without the prefix.
	 * @return array<int,float>|null Null when the meta is missing or malformed.
	 */
	private function four_numbers( $name ) {
		$parts = preg_split( '/\s+/', $this->raw( $name ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) || 4 !== count( $parts ) || 4 !== count( array_filter( $parts, 'is_numeric' ) ) ) {
			return null;
		}

		return array_map( 'floatval', $parts );
	}

	/**
	 * The page margins of the first page, when it is not laid out like the rest.
	 *
	 * A first page carries the letterhead the others do without, and several
	 * of the institutional templates give it a page setup of its own because
	 * of that: a body starting below a tall logo, a deeper foot leaving room
	 * for a signature block, or a different gutter altogether.
	 *
	 * `first-page-margins` states all four at once. `first-page-bottom` is the
	 * shorthand for the common case of a deeper foot alone, and is read only
	 * when the full form is absent.
	 *
	 * @param array<int,float> $margins Page margins the layout resolved to.
	 * @return array<int,float>|null Null when the layout keeps the same margins throughout.
	 */
	private function first_page_margins( array $margins ) {
		$first = $this->four_numbers( 'first-page-margins' );
		if ( null !== $first ) {
			return $first;
		}

		$bottom = $this->number( 'first-page-bottom', 0.0 );
		if ( $bottom <= 0 ) {
			return null;
		}

		$margins[2] = $bottom;

		return $margins;
	}
}
