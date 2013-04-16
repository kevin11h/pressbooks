<?php
/**
 * @author  PressBooks <code@pressbooks.org>
 * @license GPLv2 (or any later version)
 */
namespace PressBooks\Export\Hpub;


use PressBooks\Export\Export;
use PressBooks\Sanitize;

require_once( ABSPATH . 'wp-admin/includes/class-pclzip.php' );

/**
 * @see https://github.com/Simbul/baker/wiki/hpub-specification
 */
class Hpub extends Export {


	/**
	 * Timeout in seconds.
	 * Used with wp_remote_get()
	 *
	 * @var int
	 */
	public $timeout = 90;


	/**
	 * @var string
	 */
	protected $stylesheet;


	/**
	 * Temporary directory used to build HPub, no trailing slash!
	 *
	 * @var string
	 */
	protected $tmpDir;

	/**
	 * Generated by createContent(), used by createJson()
	 *
	 * @var array
	 */
	protected $manifest = array();


	/**
	 * We forcefully reorder some of the front-matter types to respect the Chicago Manual of Style.
	 * Keep track of where we are using this variable.
	 *
	 * @var int
	 */
	protected $frontMatterPos = 1;


	/**
	 * Last known front matter position. Used to insert the TOC in the correct place.
	 *
	 * @var int|bool
	 */
	protected $frontMatterLastPos = false;


	/**
	 * Sometimes the user will omit an introduction so we must inject the style in either the first
	 * part or the first chapter ourselves.
	 *
	 * @var bool
	 */
	protected $hasIntroduction = false;

	/**
	 * Used to set cover-image in OPF for kindlegen compatibility.
	 *
	 * @var string
	 */
	protected $coverImage;


	/**
	 * @param array $args
	 */
	function __construct( array $args ) {

		// Some defaults

		$this->tmpDir = $this->createTmpDir();
	}


	/**
	 * Delete temporary directory when done.
	 */
	function __destruct() {

		$this->deleteTmpDir();
	}


	/**
	 * Create $this->outputPath
	 *
	 * @return bool
	 */
	function convert() {

		if ( empty( $this->tmpDir ) || ! is_dir( $this->tmpDir ) ) {
			$this->logError( '$this->tmpDir must be set before calling convert().' );

			return false;
		}

		$metadata = \PressBooks\Book::getBookInformation();
		$book_contents = $this->preProcessBookContents( \PressBooks\Book::getBookContents() );

		try {

			$this->createContainer();
			$this->createContent( $book_contents, $metadata );
			$this->createJson( $book_contents, $metadata );

		} catch ( \Exception $e ) {
			$this->logError( $e->getMessage() );

			return false;
		}

		$filename = $this->timestampedFileName( '.hpub' );
		if ( ! $this->zipHpub( $filename ) ) {
			return false;
		}
		$this->outputPath = $filename;

		return true;
	}


	/**
	 * Check the sanity of $this->outputPath
	 *
	 * @return bool
	 */
	function validate() {

		// TODO: HPUB validation

		return true;
	}


	/**
	 * @param $book_contents
	 *
	 * @return mixed
	 */
	protected function preProcessBookContents( $book_contents ) {

		// We need to change global $id for shortcodes, the_content, ...
		global $id;
		$old_id = $id;

		// Do root level structures first.
		foreach ( $book_contents as $type => $struct ) {

			if ( preg_match( '/^__/', $type ) )
				continue; // Skip __magic keys

			foreach ( $struct as $i => $val ) {

				if ( isset( $val['post_content'] ) ) {
					$id = $val['ID'];
					$book_contents[$type][$i]['post_content'] = $this->preProcessPostContent( $val['post_content'] );
				}
				if ( isset( $val['post_title'] ) ) {
					$book_contents[$type][$i]['post_title'] = Sanitize\sanitize_xml_attribute( $val['post_title'] );
				}
				if ( isset( $val['post_name'] ) ) {
					$book_contents[$type][$i]['post_name'] = $this->preProcessPostName( $val['post_name'] );
				}

				if ( 'part' == $type ) {

					// Do chapters, which are embedded in part structure
					foreach ( $book_contents[$type][$i]['chapters'] as $j => $val2 ) {

						if ( isset( $val2['post_content'] ) ) {
							$id = $val2['ID'];
							$book_contents[$type][$i]['chapters'][$j]['post_content'] = $this->preProcessPostContent( $val2['post_content'] );
						}
						if ( isset( $val2['post_title'] ) ) {
							$book_contents[$type][$i]['chapters'][$j]['post_title'] = Sanitize\sanitize_xml_attribute( $val2['post_title'] );
						}
						if ( isset( $val2['post_name'] ) ) {
							$book_contents[$type][$i]['chapters'][$j]['post_name'] = $this->preProcessPostName( $val2['post_name'] );
						}

					}
				}
			}
		}

		$id = $old_id;
		return $book_contents;
	}


	/**
	 * @param string $content
	 *
	 * @return string
	 */
	protected function preProcessPostContent( $content ) {

		$content = apply_filters( 'the_content', $content );
		$content = $this->tidy( $content );

		return $content;
	}


	/**
	 * Tidy HTML
	 *
	 * @param string $html
	 *
	 * @return string
	 */
	protected function tidy( $html ) {

		// We don't run any additional tidy procedures for this output.
		// The HTML parser should gracefully handle whatever WordPress spits out.
		// Override this method in a child class if you need additional sanity control.

		return $html;
	}


	/**


	/**
	 * Create a temporary directory
	 *
	 * @throws \Exception
	 */
	protected function deleteTmpDir() {

		// Cleanup temporary directory, if any
		if ( ! empty( $this->tmpDir ) ) {
			$this->obliterateDir( $this->tmpDir );
		}
	}


	/**
	 * Zip the contents of an Hpub
	 *
	 * @param $filename
	 *
	 * @return bool
	 */
	protected function zipHpub( $filename ) {

		$zip = new \PclZip( $filename );

		$files = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->tmpDir ) ) as $file ) {
			if ( ! $file->isFile() ) continue;
			$files[] = $file->getPathname();
		}

		$list = $zip->add( $files, '', $this->tmpDir );
		if ( $list == 0 ) {
			return false;
		}

		return true;
	}


	/**
	 * Create HPub 1 container.
	 */
	protected function createContainer() {

		mkdir( $this->tmpDir . '/css' );
		mkdir( $this->tmpDir . '/gfx' );
		mkdir( $this->tmpDir . '/images' );
		mkdir( $this->tmpDir . '/js' );
	}


	/**
	 * Create Content
	 *
	 * @param array $book_contents
	 * @param array $metadata
	 */
	protected function createContent( $book_contents, $metadata ) {

		// First, setup and affect $this->stylesheet
		$this->createStylesheet();

		// Reset manifest
		$this->manifest = array();

		/* Note: order affects $this->manifest */

		// Cover
		$this->createCover( $book_contents, $metadata );

		// Title
		$this->createTitle( $book_contents, $metadata );

		// Copyright
		$this->createCopyright( $book_contents, $metadata );

		// Dedication and Epigraph (In that order!)
		$this->createDedicationAndEpigraph( $book_contents, $metadata );

		// Front-matter
		$this->createFrontMatter( $book_contents, $metadata );

		// Parts, Chapters
		$this->createPartsAndChapters( $book_contents, $metadata );

		// Back-matter
		$this->createBackMatter( $book_contents, $metadata );

		// Table of contents
		// IMPORTANT: Do this last! Uses $this->manifest to generate itself
		$this->createToc( $book_contents, $metadata );
	}


	/**
	 * Create stylesheet. Change $this->stylesheet to a filename used by subsequent methods.
	 */
	protected function createStylesheet() {

		// TODO, support more than one stylesheet
		$stylesheet = 'example.css';

		$path_to_original_stylesheet = __DIR__ . "/templates/css/$stylesheet";
		$path_to_tmp_stylesheet = $this->tmpDir . "/css/$stylesheet";

		file_put_contents(
			$path_to_tmp_stylesheet,
			$this->loadTemplate( $path_to_original_stylesheet ) );

		$this->scrapeAndKneadCss( $path_to_original_stylesheet, $path_to_tmp_stylesheet );

		$this->stylesheet = $stylesheet;
	}


	/**
	 * Parse CSS, copy assets, rewrite copy.
	 *
	 * @param string $path_to_original_stylesheet*
	 * @param string $path_to_copy_of_stylesheet
	 */
	protected function scrapeAndKneadCss( $path_to_original_stylesheet, $path_to_copy_of_stylesheet ) {

		$css_dir = pathinfo( $path_to_original_stylesheet, PATHINFO_DIRNAME );
		$css = file_get_contents( $path_to_copy_of_stylesheet );
		$fullpath = $this->tmpDir . '/images';

		// Search for url("*"), url('*'), and url(*)
		preg_match_all( '/url\(([\s])?([\"|\'])?(.*?)([\"|\'])?([\s])?\)/i', $css, $matches, PREG_PATTERN_ORDER );

		// Remove duplicates, sort by biggest to smallest to prevent substring replacements
		$matches = array_unique( $matches[3] );
		usort( $matches, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		foreach ( $matches as $url ) {
			$filename = sanitize_file_name( basename( $url ) );

			if ( preg_match( '#^\.\./images/#', $url ) && substr_count( $url, '/' ) == 2 ) {

				// Look for "^../images/"
				// Count 2 slashes so that we don't touch stuff like "^images/out/of/bounds/"	or "^images/../../denied/"

				$my_image = realpath( "$css_dir/$url" );
				if ( $my_image ) {
					copy( $my_image, "$fullpath/$filename" );
				}

			} elseif ( preg_match( '#^https?://#i', $url ) && preg_match( '/(\.jpe?g|\.gif|\.png)$/i', $url ) ) {

				// Look for images via http(s), pull them in locally

				if ( $new_filename = $this->fetchAndSaveUniqueImage( $url, $fullpath ) ) {
					$css = str_replace( $url, "../images/$new_filename", $css );
				}
			}

		}

		// Overwrite the new file with new info
		file_put_contents( $path_to_copy_of_stylesheet, $css );

	}


	/**
	 * @param array $book_contents
	 * @param array $metadata
	 */
	protected function createCover( $book_contents, $metadata ) {

		// Resize Image

		if ( ! empty( $metadata['pb_cover_image'] ) && ! preg_match( '~assets/images/default-book-cover.png$~', $metadata['pb_cover_image'] ) ) {
			$source_path = \PressBooks\Utility\get_media_path( $metadata['pb_cover_image'] );
		} else {
			$source_path = PB_PLUGIN_DIR . 'assets/images/default-book-cover.png';
		}
		$dest_image = basename( $source_path );
		$dest_path = $this->tmpDir . "/images/" . $dest_image;

		$img = wp_get_image_editor( $source_path );
		if ( ! is_wp_error( $img ) ) {
			// Take the longest dimension of the image and resize.
			// Cropping is turned off. The aspect ratio is maintained.
			$img->resize( 1563, 2500, false );
			$img->save( $dest_path );
			$this->coverImage = $dest_image;
		}


		// HTML

		$html = '<div id="cover-image">';
		if ( $this->coverImage ) {
			$html .= sprintf( '<img src="images/%s" alt="%s" />', $this->coverImage, get_bloginfo( 'name' ) );
		}
		$html .= "</div>\n";

		// Create file, insert into manifest

		$vars = array(
			'post_title' => __( 'Cover', 'pressbooks' ),
			'stylesheet' => $this->stylesheet,
			'post_content' => $html,
		);

		$file_id = 'front-cover';
		$filename = "{$file_id}.html";

		file_put_contents(
			$this->tmpDir . "/$filename",
			$this->loadTemplate( __DIR__ . '/templates/html.php', $vars ) );

		$this->manifest[$file_id] = array(
			'ID' => -1,
			'post_title' => $vars['post_title'],
			'filename' => $filename,
		);

	}


	/**
	 * @param array $book_contents
	 * @param array $metadata
	 */
	protected function createTitle( $book_contents, $metadata ) {

		// Look for custom title-page

		$content = '';
		foreach ( $book_contents['front-matter'] as $front_matter ) {

			if ( ! $front_matter['export'] )
				continue; // Skip

			$id = $front_matter['ID'];
			$subclass = \PressBooks\Taxonomy\front_matter_type( $id );

			if ( 'title-page' != $subclass )
				continue; // Skip

			$content = $this->kneadHtml( $front_matter['post_content'], 'front-matter' );
			break;
		}

		// HTML

		$html = '<div id="title-page">';
		if ( $content ) {
			$html .= $content;
		} else {
			$html .= sprintf( '<h1 class="title">%s</h1>', get_bloginfo( 'name' ) );
			$html .= sprintf( '<h2 class="subtitle">%s</h2>', @$metadata['pb_subtitle'] );
			$html .= sprintf( '<div class="logo"></div>' );
			$html .= sprintf( '<h3 class="author">%s</h3>', @$metadata['pb_author'] );
			$html .= sprintf( '<h4 class="publisher">%s</h4>', @$metadata['pb_publisher'] );
			$html .= sprintf( '<h5 class="publisher-city">%s</h5>', @$metadata['pb_publisher_city'] );
		}
		$html .= "</div>\n";

		// Create file, insert into manifest

		$vars = array(
			'post_title' => __( 'Title Page', 'pressbooks' ),
			'stylesheet' => $this->stylesheet,
			'post_content' => $html,
		);

		$file_id = 'title-page';
		$filename = "{$file_id}.html";

		file_put_contents(
			$this->tmpDir . "/$filename",
			$this->loadTemplate( __DIR__ . '/templates/html.php', $vars ) );

		$this->manifest[$file_id] = array(
			'ID' => -1,
			'post_title' => $vars['post_title'],
			'filename' => $filename,
		);

	}


	/**
	 * @param array $book_contents
	 * @param array $metadata
	 */
	protected function createCopyright( $book_contents, $metadata ) {

		// HTML

		$html = '<div id="copyright-page"><div class="ugc">';

		if ( ! empty( $metadata['pb_custom_copyright'] ) ) {
			$html .= $this->kneadHtml( $this->tidy( $metadata['pb_custom_copyright'] ), 'custom' );
		} else {
			$html .= '<p>';
			$html .= get_bloginfo( 'name' ) . ' ' . __( 'Copyright', 'pressbooks' ) . ' &#169; ';
			$html .= ( ! empty( $metadata['pb_copyright_year'] ) ) ? $metadata['pb_copyright_year'] : date( 'Y' );
			if ( ! empty( $metadata['pb_copyright_holder'] ) ) $html .= ' ' . __( 'by', 'pressbooks' ) . ' ' . $metadata['pb_copyright_holder'] . '. ';
			$html .= '</p>';
		}

		$freebie_notice = 'This book was produced using <a href="http://pressbooks.com/">PressBooks.com</a>.';
		$html .= "<p>$freebie_notice</p>";

		$html .= "</div></div>\n";

		// Create file, insert into manifest

		$vars = array(
			'post_title' => __( 'Copyright', 'pressbooks' ),
			'stylesheet' => $this->stylesheet,
			'post_content' => $html,
		);

		$file_id = 'copyright';
		$filename = "{$file_id}.html";

		file_put_contents(
			$this->tmpDir . "/$filename",
			$this->loadTemplate( __DIR__ . '/templates/html.php', $vars ) );

		$this->manifest[$file_id] = array(
			'ID' => - 1,
			'post_title' => $vars['post_title'],
			'filename' => $filename,
		);

	}


	/**
	 * @param array $book_contents
	 * @param array $metadata
	 */
	protected function createDedicationAndEpigraph( $book_contents, $metadata ) {

		$front_matter_printf = '<div class="front-matter %s" id="%s">';
		$front_matter_printf .= '<div class="front-matter-title-wrap"><h3 class="front-matter-number">%s</h3><h1 class="front-matter-title">%s</h1></div>';
		$front_matter_printf .= '<div class="ugc front-matter-ugc">%s</div>%s';
		$front_matter_printf .= '</div>';

		$vars = array(
			'post_title' => '',
			'stylesheet' => $this->stylesheet,
			'post_content' => '',
		);

		$i = 1;
		$last_pos = false;
		foreach ( array( 'dedication', 'epigraph' ) as $compare ) {
			foreach ( $book_contents['front-matter'] as $front_matter ) {

				if ( ! $front_matter['export'] )
					continue; // Skip

				$id = $front_matter['ID'];
				$subclass = \PressBooks\Taxonomy\front_matter_type( $id );

				if ( $compare != $subclass )
					continue; //Skip

				$slug = $front_matter['post_name'];
				$title = ( get_post_meta( $id, 'pb_show_title', true ) ? $front_matter['post_title'] : '' );
				$content = $this->kneadHtml( $front_matter['post_content'], 'front-matter', $i );

				$vars['post_title'] = $front_matter['post_title'];
				$vars['post_content'] = sprintf( $front_matter_printf,
					$subclass,
					$slug,
					$i,
					Sanitize\decode( $title ),
					$content,
					'' );

				$file_id = 'front-matter-' . sprintf( "%03s", $i );
				$filename = "{$file_id}-{$slug}.html";

				file_put_contents(
					$this->tmpDir . "/$filename",
					$this->loadTemplate( __DIR__ . '/templates/html.php', $vars ) );

				$this->manifest[$file_id] = array(
					'ID' => $front_matter['ID'],
					'post_title' => $front_matter['post_title'],
					'filename' => $filename,
				);

				++$i;
				$last_pos = $i;
			}
		}
		$this->frontMatterPos = $i;
		if ( $last_pos ) $this->frontMatterLastPos = $last_pos - 1;
	}


	/**
	 * @param array $book_contents
	 * @param array $metadata
	 */
	protected function createFrontMatter( $book_contents, $metadata ) {

		$front_matter_printf = '<div class="front-matter %s" id="%s">';
		$front_matter_printf .= '<div class="front-matter-title-wrap"><h3 class="front-matter-number">%s</h3><h1 class="front-matter-title">%s</h1></div>';
		$front_matter_printf .= '<div class="ugc front-matter-ugc">%s</div>%s';
		$front_matter_printf .= '</div>';

		$vars = array(
			'post_title' => '',
			'stylesheet' => $this->stylesheet,
			'post_content' => '',
		);

		$i = $this->frontMatterPos;
		foreach ( $book_contents['front-matter'] as $front_matter ) {

			if ( ! $front_matter['export'] )
				continue; // Skip

			$id = $front_matter['ID'];
			$subclass = \PressBooks\Taxonomy\front_matter_type( $id );

			if ( 'dedication' == $subclass || 'epigraph' == $subclass || 'title-page' == $subclass )
				continue; // Skip

			if ( 'introduction' == $subclass )
				$this->hasIntroduction = true;

			$slug = $front_matter['post_name'];
			$title = ( get_post_meta( $id, 'pb_show_title', true ) ? $front_matter['post_title'] : '' );
			$content = $this->kneadHtml( $front_matter['post_content'], 'front-matter', $i );

			$short_title = trim( get_post_meta( $id, 'pb_short_title', true ) );
			$subtitle = trim( get_post_meta( $id, 'pb_subtitle', true ) );
			$author = trim( get_post_meta( $id, 'pb_section_author', true ) );

			if ( $author ) {
				$content = '<h2 class="chapter-author">' . Sanitize\decode( $author ) . '</h2>' . $content;
			}

			if ( $subtitle ) {
				$content = '<h2 class="chapter-subtitle">' . Sanitize\decode( $subtitle ) . '</h2>' . $content;
			}

			if ( $short_title ) {
				$content = '<h6 class="short-title">' . Sanitize\decode( $short_title ) . '</h6>' . $content;
			}

			$vars['post_title'] = $front_matter['post_title'];
			$vars['post_content'] = sprintf( $front_matter_printf,
				$subclass,
				$slug,
				$i,
				Sanitize\decode( $title ),
				$content,
				'' );

			$file_id = 'front-matter-' . sprintf( "%03s", $i );
			$filename = "{$file_id}-{$slug}.html";

			file_put_contents(
				$this->tmpDir . "/$filename",
				$this->loadTemplate( __DIR__ . '/templates/html.php', $vars ) );

			$this->manifest[$file_id] = array(
				'ID' => $front_matter['ID'],
				'post_title' => $front_matter['post_title'],
				'filename' => $filename,
			);

			++$i;
		}

		$this->frontMatterPos = $i;
	}


	/**
	 * @param array $book_contents
	 * @param array $metadata
	 */
	protected function createPartsAndChapters( $book_contents, $metadata ) {

		$part_printf = '<div class="part" id="%s">';
		$part_printf .= '<div class="part-title-wrap"><h3 class="part-number">%s</h3><h1 class="part-title">%s</h1></div>';
		$part_printf .= '</div>';

		$chapter_printf = '<div class="chapter" id="%s">';
		$chapter_printf .= '<div class="chapter-title-wrap"><h3 class="chapter-number">%s</h3><h2 class="chapter-title">%s</h2></div>';
		$chapter_printf .= '<div class="ugc chapter-ugc">%s</div>%s';
		$chapter_printf .= '</div>';

		$vars = array(
			'post_title' => '',
			'stylesheet' => $this->stylesheet,
			'post_content' => '',
		);

		// Parts, Chapters
		$i = $j = 1;
		foreach ( $book_contents['part'] as $part ) {

			$part_printf_changed = '';
			$array_pos = count( $this->manifest );
			$has_chapters = false;

			// Inject introduction class?
			if ( ! $this->hasIntroduction && count( $book_contents['part'] ) > 1 ) {
				$part_printf_changed = str_replace( '<div class="part" id=', '<div class="part introduction" id=', $part_printf );
				$this->hasIntroduction = true;
			}

			foreach ( $part['chapters'] as $chapter ) {

				if ( ! $chapter['export'] )
					continue; // Skip

				$chapter_printf_changed = '';
				$id = $chapter['ID'];
				$slug = $chapter['post_name'];
				$title = ( get_post_meta( $id, 'pb_show_title', true ) ? $chapter['post_title'] : '' );
				$content = $this->kneadHtml( $chapter['post_content'], 'chapter', $j );

				$short_title = trim( get_post_meta( $id, 'pb_short_title', true ) );
				$subtitle = trim( get_post_meta( $id, 'pb_subtitle', true ) );
				$author = trim( get_post_meta( $id, 'pb_section_author', true ) );

				if ( $author ) {
					$content = '<h2 class="chapter-author">' . Sanitize\decode( $author ) . '</h2>' . $content;
				}

				if ( $subtitle ) {
					$content = '<h2 class="chapter-subtitle">' . Sanitize\decode( $subtitle ) . '</h2>' . $content;
				}

				if ( $short_title ) {
					$content = '<h6 class="short-title">' . Sanitize\decode( $short_title ) . '</h6>' . $content;
				}


				// Inject introduction class?
				if ( ! $this->hasIntroduction ) {
					$chapter_printf_changed = str_replace( '<div class="chapter" id=', '<div class="chapter introduction" id=', $chapter_printf );
					$this->hasIntroduction = true;
				}

				$vars['post_title'] = $chapter['post_title'];
				$vars['post_content'] = sprintf(
					( $chapter_printf_changed ? $chapter_printf_changed : $chapter_printf ),
					$slug,
					$j,
					Sanitize\decode( $title ),
					$content,
					'' );

				$file_id = 'chapter-' . sprintf( "%03s", $j );
				$filename = "{$file_id}-{$slug}.html";

				file_put_contents(
					$this->tmpDir . "/$filename",
					$this->loadTemplate( __DIR__ . '/templates/html.php', $vars ) );

				$this->manifest[$file_id] = array(
					'ID' => $chapter['ID'],
					'post_title' => $chapter['post_title'],
					'filename' => $filename,
				);

				$has_chapters = true;

				++$j;
			}

			if ( $has_chapters && count( $book_contents['part'] ) > 1 ) {

				$slug = $part['post_name'];

				$vars['post_title'] = $part['post_title'];
				$vars['post_content'] = sprintf(
					( $part_printf_changed ? $part_printf_changed : $part_printf ),
					$slug,
					$i,
					Sanitize\decode( $part['post_title'] ) );

				$file_id = 'part-' . sprintf( "%03s", $i );
				$filename = "{$file_id}-{$slug}.html";

				file_put_contents(
					$this->tmpDir . "/$filename",
					$this->loadTemplate( __DIR__ . '/templates/html.php', $vars ) );

				// Insert into correct pos
				$this->manifest = array_slice( $this->manifest, 0, $array_pos, true ) + array(
					$file_id => array(
						'ID' => $part['ID'],
						'post_title' => $part['post_title'],
						'filename' => $filename,
					) ) + array_slice( $this->manifest, $array_pos, count( $this->manifest ) - 1, true );

				++$i;
			}

			// Did we actually inject the introduction class?
			if ( $part_printf_changed && ! $has_chapters ) {
				$this->hasIntroduction = false;
			}

		}
	}


	/**
	 * @param array $book_contents
	 * @param array $metadata
	 */
	protected function createBackMatter( $book_contents, $metadata ) {

		$back_matter_printf = '<div class="back-matter %s" id="%s">';
		$back_matter_printf .= '<div class="back-matter-title-wrap"><h3 class="back-matter-number">%s</h3><h1 class="back-matter-title">%s</h1></div>';
		$back_matter_printf .= '<div class="ugc back-matter-ugc">%s</div>%s';
		$back_matter_printf .= '</div>';

		$vars = array(
			'post_title' => '',
			'stylesheet' => $this->stylesheet,
			'post_content' => '',
		);

		$i = 1;
		foreach ( $book_contents['back-matter'] as $back_matter ) {

			if ( ! $back_matter['export'] )
				continue; // Skip

			$id = $back_matter['ID'];
			$subclass = \PressBooks\Taxonomy\back_matter_type( $id );
			$slug = $back_matter['post_name'];
			$title = ( get_post_meta( $id, 'pb_show_title', true ) ? $back_matter['post_title'] : '' );
			$content = $this->kneadHtml( $back_matter['post_content'], 'back-matter', $i );

			$vars['post_title'] = $back_matter['post_title'];
			$vars['post_content'] = sprintf( $back_matter_printf,
				$subclass,
				$slug,
				$i,
				Sanitize\decode( $title ),
				$content,
				'' );

			$file_id = 'back-matter-' . sprintf( "%03s", $i );
			$filename = "{$file_id}-{$slug}.html";

			file_put_contents(
				$this->tmpDir . "/$filename",
				$this->loadTemplate( __DIR__ . '/templates/html.php', $vars ) );

			$this->manifest[$file_id] = array(
				'ID' => $back_matter['ID'],
				'post_title' => $back_matter['post_title'],
				'filename' => $filename,
			);

			++$i;
		}

	}


	/**
	 * Uses $this->manifest to generate itself.
	 *
	 * @param array $book_contents
	 * @param array $metadata
	 */
	protected function createToc( $book_contents, $metadata ) {

		$vars = array(
			'post_title' => '',
			'stylesheet' => $this->stylesheet,
			'post_content' => '',
		);

		// Start by inserting self into correct manifest position
		$array_pos = $this->positionOfToc();

		$file_id = 'table-of-contents';
		$filename = "{$file_id}.html";
		$vars['post_title'] = __( 'Table Of Contents', 'pressbooks' );

		$this->manifest = array_slice( $this->manifest, 0, $array_pos + 1, true ) + array(
			$file_id => array(
				'ID' => - 1,
				'post_title' => $vars['post_title'],
				'filename' => $filename,
			) ) + array_slice( $this->manifest, $array_pos + 1, count( $this->manifest ) - 1, true );

		// HTML

		$i = 1;
		$html = '<div id="toc"><h1>' . __( 'Contents', 'pressbooks' ) . '</h1><ul>';
		foreach ( $this->manifest as $k => $v ) {

			// We only care about front-matter, part, chapter, back-matter
			// Skip the rest

			$subtitle = '';
			$author = '';
			if ( preg_match( '/^front-matter-/', $k ) ) {
				$class = 'front-matter ';
				$class .= \PressBooks\Taxonomy\front_matter_type( $v['ID'] );
				$subtitle = trim( get_post_meta( $v['ID'], 'pb_subtitle', true ) );
				$author = trim( get_post_meta( $v['ID'], 'pb_section_author', true ) );
			} elseif ( preg_match( '/^part-/', $k ) ) {
				$class = 'part';
			} elseif ( preg_match( '/^chapter-/', $k ) ) {
				$class = 'chapter';
				$subtitle = trim( get_post_meta( $v['ID'], 'pb_subtitle', true ) );
				$author = trim( get_post_meta( $v['ID'], 'pb_section_author', true ) );
				++$i;
			} elseif ( preg_match( '/^back-matter-/', $k ) ) {
				$class = 'back-matter ';
				$class .= \PressBooks\Taxonomy\back_matter_type( $v['ID'] );
			} else {
				continue;
			}

			$html .= sprintf( '<li class="%s"><a href="%s">%s', $class, $v['filename'], Sanitize\decode( $v['post_title'] ) );

			if ( $subtitle )
				$html .= ' <span class="chapter-subtitle">' . Sanitize\decode( $subtitle ) . '</span>';

			if ( $author )
				$html .= ' <span class="chapter-author">' . Sanitize\decode( $author ) . '</span>';

			$html .= "</a></li>\n";

		}
		$html .= "</ul></div>\n";

		// Create file

		$vars['post_content'] = $html;

		file_put_contents(
			$this->tmpDir . "/$filename",
			$this->loadTemplate( __DIR__ . '/templates/html.php', $vars ) );

	}


	/**
	 * Determine position of TOC based on Chicago Manual Of Style.
	 *
	 * @return int
	 */
	protected function positionOfToc() {

		$search = array_keys( $this->manifest );

		if ( false == $this->frontMatterLastPos ) {

			$array_pos = array_search( 'copyright', $search );
			if ( false === $array_pos ) $array_pos = - 1;

		} else {

			$array_pos = - 1;
			$preg = '/^front-matter-' . sprintf( "%03s", $this->frontMatterLastPos ) . '$/';
			foreach ( $search as $key => $val ) {
				if ( preg_match( $preg, $val ) ) {
					$array_pos = $key;
					break;
				}
			}

		}

		return $array_pos;
	}


	/**
	 * Pummel the HTML into HPub compatible dough.
	 *
	 * @param string $html
	 * @param string $type front-matter, part, chapter, back-matter, ...
	 * @param int $pos (optional) position of content, used when creating filenames like: chapter-001, chapter-002, ...
	 *
	 * @return string
	 */
	protected function kneadHtml( $html, $type, $pos = 0 ) {

		libxml_use_internal_errors( true );

		// Load HTMl snippet into DOMDocument using UTF-8 hack
		$doc = new \DOMDocument();
		$doc->loadHTML( '<?xml version="1.0" encoding="UTF-8"?>' . $html );

		// Download images, change to relative paths
		$doc = $this->scrapeAndKneadImages( $doc );

		// Deal with <a href="">, <a href=''>, and other mutations
		$doc = $this->kneadHref( $doc, $type, $pos );

		// If you are storing multi-byte characters in XML, then saving the XML using saveXML() will create problems.
		// Ie. It will spit out the characters converted in encoded format. Instead do the following:
		$html = $doc->saveHtml( $doc->documentElement );

		// Remove auto-created <html> <body> and <!DOCTYPE> tags.
		$html = preg_replace( '/^<!DOCTYPE.+?>/', '', str_replace( array( '<html>', '</html>', '<body>', '</body>' ), array( '', '', '', '' ), $html ) );

		// HTML5, prevent self closing of all empty HTML tags besides a certain set
		$html = preg_replace_callback( '#<(\w+)([^>]*)\s*/>#s', function ( $matches ) {
			// Ignore these tags
			$xhtml_tags = array( 'br', 'hr', 'input', 'frame', 'img', 'area', 'link', 'col', 'base', 'basefont', 'param', 'meta' );
			// if a element that is not in the above list is empty, it should close like   `<element></element>` (for eg. empty `<title>`)
			return in_array( $matches[1], $xhtml_tags ) ? "<{$matches[1]}{$matches[2]} />" : "<{$matches[1]}{$matches[2]}></{$matches[1]}>";
		}, $html );

		$errors = libxml_get_errors(); // TODO: Handle errors gracefully
		libxml_clear_errors();

		return $html;
	}


	/**
	 * Parse HTML snippet, download all found <img> tags into /OEBPS/images/, return the HTML with changed <img> paths.
	 *
	 * @param \DOMDocument $doc
	 *
	 * @return \DOMDocument
	 */
	protected function scrapeAndKneadImages( \DOMDocument $doc ) {

		$fullpath = $this->tmpDir . '/images';

		$images = $doc->getElementsByTagName( 'img' );
		foreach ( $images as $image ) {
			// Fetch image, change src
			$url = $image->getAttribute( 'src' );
			if ( $filename = $this->fetchAndSaveUniqueImage( $url, $fullpath ) ) {
				$image->setAttribute( 'src', 'images/' . $filename );
			}
		}

		return $doc;
	}


	/**
	 * Fetch a url with wp_remote_get(), save it to $fullpath with a unique name.
	 *
	 * @param $url string
	 * @param $fullpath string
	 *
	 * @return string filename
	 */
	protected function fetchAndSaveUniqueImage( $url, $fullpath ) {

		$response = wp_remote_get( $url, array( 'timeout' => $this->timeout ) );

		// WordPress error?
		if ( is_wp_error( $response ) ) {
			// TODO: handle $response->get_error_message();
			return '';
		}

		$filename = array_shift( explode( '?', basename( $url ) ) ); // Basename without query string
		$filename = sanitize_file_name( urldecode( $filename ) );
		$filename = Sanitize\force_ascii( $filename );

		$file_contents = wp_remote_retrieve_body( $response );

		// Check if file is actually an image
		$im = @imagecreatefromstring( $file_contents );
		if ( $im === false ) {
			return ''; // Not an image
		}
		unset( $im );

		// Check for duplicates, save accordingly
		if ( ! file_exists( "$fullpath/$filename" ) ) {
			file_put_contents( "$fullpath/$filename", $file_contents );
		} elseif ( md5( $file_contents ) != md5( file_get_contents( "$fullpath/$filename" ) ) ) {
			$filename = wp_unique_filename( $fullpath, $filename );
			file_put_contents( "$fullpath/$filename", $file_contents );
		}

		return $filename;
	}


	/**
	 * Change hrefs
	 *
	 * @param \DOMDocument $doc
	 * @param string $type front-matter, part, chapter, back-matter, ...
	 * @param int $pos (optional) position of content, used when creating filenames like: chapter-001, chapter-002, ...
	 *
	 * @return \DOMDocument
	 */
	protected function kneadHref( \DOMDocument $doc, $type, $pos ) {

		$urls = $doc->getElementsByTagName( 'a' );
		foreach ( $urls as $url ) {

			$current_url = '' . $url->getAttribute( 'href' ); // Stringify

			// Don't touch empty urls
			if ( ! trim( $current_url ) )
				continue;

			// WordPress auto wraps images in a href tags.
			// For example: <a href="some_image-original.png"><img src="some_image-300x200.png" /></a>
			// This causes an EPUB validation error of: hyperlink to non-standard resource ( of type 'image/...' )
			// We fix this by removing the href
			if ( $url->childNodes->length ) foreach ( $url->childNodes as $node ) {
				if ( 'img' == $node->nodeName && $this->fuzzyImageNameMatch( $current_url, $node->getAttribute( 'src' ) ) ) {
					$url->removeAttribute( 'href' );
					continue 2;
				}
			}

			// Determine if we are trying to link to our own internal content
			$internal_url = $this->fuzzyHrefMatch( $current_url, $type, $pos );
			if ( false !== $internal_url ) {
				$url->setAttribute( 'href', $internal_url );
				continue;
			}

			// Canonicalize, fix typos, remove garbage
			if ( '#' != @$current_url[0] ) {
				$url->setAttribute( 'href', \PressBooks\Sanitize\canonicalizeUrl( $current_url ) );
			}

		}

		return $doc;
	}


	/**
	 * Fuzzy image name match.
	 * For example: <a href="Some_Image-original.png"><img src="some_image-300x200.PNG" /></a>
	 * We consider both 'href' and 'src' above 'the same'
	 *
	 * @param string $file1
	 * @param string $file2
	 *
	 * @return bool
	 */
	protected function fuzzyImageNameMatch( $file1, $file2 ) {

		$file1 = basename( $file1 );
		$file2 = basename( $file2 );

		/* Compare extensions */

		$file1 = explode( '.', $file1 );
		$ext1 = strtolower( end( $file1 ) );

		$file2 = explode( '.', $file2 );
		$ext2 = strtolower( end( $file2 ) );

		if ( $ext1 != $ext2 ) {
			return false;
		}

		/* Compare prefixes */

		$pre1 = explode( '-', $file1[0] );
		$pre1 = strtolower( $pre1[0] );

		$pre2 = explode( '-', $file2[0] );
		$pre2 = strtolower( $pre2[0] );

		if ( $pre1 != $pre2 ) {
			return false;
		}

		return true;
	}


	/**
	 * Try to determine if a URL is pointing to internal content.
	 *
	 * @param $url
	 * @param string $type front-matter, part, chapter, back-matter, ...
	 * @param int $pos (optional) position of content, used when creating filenames like: chapter-001, chapter-002, ...
	 *
	 * @return bool|string
	 */
	protected function fuzzyHrefMatch( $url, $type, $pos ) {

		if ( ! $pos )
			return false;

		$url = trim( $url );
		$url = rtrim( $url, '/' );

		$last_part = explode( '/', $url );
		$last_part = trim( end( $last_part ) );

		if ( ! $last_part )
			return false;

		$lookup = \PressBooks\Book::getBookStructure();
		$lookup = $lookup['__export_lookup'];

		if ( ! isset( $lookup[$last_part] ) )
			return false;

		$domain = parse_url( $url );
		$domain = @$domain['host'];

		if ( $domain ) {
			$domain2 = parse_url( wp_guess_url() );
			if ( $domain != @$domain2['host'] ) {
				return false;
			}
		}

		// Seems legit...

		$new_type = $lookup[$last_part];
		$new_pos = 0;
		foreach ( $lookup as $p => $t ) {
			if ( $t == $new_type ) ++$new_pos;
			if ( $p == $last_part ) break;
		}
		$new_url = "$new_type-" . sprintf( "%03s", $new_pos ) . "-$last_part.html";

		return $new_url;
	}


	/**
	 * Create JSON file.
	 *
	 * @param array $book_contents
	 * @param array $metadata
	 *
	 * @throws \Exception
	 */
	protected function createJson( $book_contents, $metadata ) {

		if ( empty( $this->manifest ) ) {
			throw new \Exception( '$this->manifest cannot be empty. Did you forget to call $this->createContent() ?' );
		}

		// Remove http://, https://, ftp://, gopher://, fake://, prepend book://
		$book_url = get_bloginfo( 'url' );
		if ( mb_strpos( $book_url, '://' ) ) list( $_garbage, $book_url ) = mb_split( '://', $book_url );
		$book_url = 'book://' . $book_url;

		$json = array(
			'title' => get_bloginfo( 'name' ),
			'author' => @$metadata['pb_author'],
			'url' => $book_url,
		);

		if ( $this->coverImage ) {
			$json['cover'] = "images/{$this->coverImage}";
		}

		foreach ( $this->manifest as $k => $v ) {
			$json['contents'][] = $v['filename'];
		}

		file_put_contents(
			$this->tmpDir . "/book.json",
			json_encode( $json ) );

	}


}
