<?php
/**
 * Base test class for Resolate plugin tests.
 *
 * @package Resolate
 */

class Resolate_Test_Base extends WP_UnitTestCase {

	/**
	 * Extends the factory method to include custom factories.
	 *
	 * @return WP_UnitTest_Factory The extended factory object.
	 */
	public static function factory() {
		// Retrieve the base factory.
		$factory = parent::factory();

		// Add custom factories if they do not already exist.
		if ( ! isset( $factory->doctype ) ) {
			$factory->doctype = new WP_UnitTest_Factory_For_Resolate_Doc_Type( $factory );
		}

		if ( ! isset( $factory->document ) ) {
			$factory->document = new WP_UnitTest_Factory_For_Resolate_Document( $factory );
		}



		// if ( ! isset( $factory->board ) ) {
		// 	$factory->board = new WP_UnitTest_Factory_For_Resolate_Board( $factory );
		// }

		// if ( ! isset( $factory->label ) ) {
		// 	$factory->label = new WP_UnitTest_Factory_For_Resolate_Label( $factory );
		// }

		// if ( ! isset( $factory->task ) ) {
		// 	$factory->task = new WP_UnitTest_Factory_For_Resolate_Task( $factory );
		// }

		// if ( ! isset( $factory->event ) ) {
		// 	$factory->event = new WP_UnitTest_Factory_For_Resolate_Event( $factory );
		// }

		return $factory;
	}

	/**
	 * Sets up custom factories before running any tests in the class.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		// Ensure custom factories are registered before the suite runs.
		self::factory();
	}

	/**
	 * Cleans up after all tests in the class have been executed.
	 */
	public static function tear_down_after_class() {
		parent::tear_down_after_class();
		// Additional cleanup can be added here if necessary.
	}
}
