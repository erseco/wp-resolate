<?php
/**
 * Demo data generator for the Resolate plugin.
 *
 * @package Resolate
 * @subpackage Resolate/includes
 */

/**
 * Class for generating demo data.
 */
class Resolate_Demo_Data {

	/**
	 * Create sample data for Resolate Plugin.
	 *
	 * This method creates 10 labels, 5 boards and 10 tasks per board.
	 */
	public function create_sample_data() {
		// Temporarily elevate permissions.
		$current_user = wp_get_current_user();
		$old_user = $current_user;
		wp_set_current_user( 1 ); // Switch to admin user (ID 1).

		$labels = $this->create_labels();
		$boards = $this->create_boards();
		$this->create_tasks( $boards, $labels );
		$this->create_kb_articles( $labels );
		$this->create_events();

		// Set up alert settings for demo data.
		$options = get_option( 'resolate_settings', array() );
		$options['alert_color'] = 'danger';
		$options['alert_message'] = '<strong>' . __( 'Warning', 'resolate' ) . ':</strong> ' . __( 'You are running this site with demo data.', 'resolate' );
		update_option( 'resolate_settings', $options );

		// Restore original user.
		wp_set_current_user( $old_user->ID );
	}

	/**
	 * Creates sample labels.
	 *
	 * @return array Array of label term IDs.
	 */
	private function create_labels() {
		$labels = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$term_name = "Label $i";
			$term_slug = sanitize_title( $term_name );
			$term_color = $this->generate_random_color();

			// Check if the label already exists.
			$existing_term = term_exists( $term_slug, 'resolate_label' );
			if ( $existing_term ) {
				$labels[] = $existing_term['term_id'];
				continue;
			}

			$term = wp_insert_term(
				$term_name,
				'resolate_label',
				array(
					'slug' => $term_slug,
				)
			);

			if ( ! is_wp_error( $term ) ) {
				add_term_meta( $term['term_id'], 'term-color', $term_color, true );
				$labels[] = $term['term_id'];
			}
		}
		return $labels;
	}

	/**
	 * Creates sample boards with different visibility settings.
	 *
	 * @return array Array of board term IDs.
	 */
	private function create_boards() {
		$boards = array();
		$visibility_settings = array(
			// Board 1: Visible in both Boards and KB.
			array(
				'name' => 'Board 1',
				'show_in_boards' => true,
				'show_in_kb' => true,
			),
			// Board 2: Visible only in Boards.
			array(
				'name' => 'Board 2',
				'show_in_boards' => true,
				'show_in_kb' => false,
			),
			// Board 3: Visible only in KB.
			array(
				'name' => 'Board 3',
				'show_in_boards' => false,
				'show_in_kb' => true,
			),
			// Board 4: Not visible in either (hidden).
			array(
				'name' => 'Board 4',
				'show_in_boards' => false,
				'show_in_kb' => false,
			),
			// Board 5: Visible in both.
			array(
				'name' => 'Board 5',
				'show_in_boards' => true,
				'show_in_kb' => true,
			),
			// Board 6: Visible in both.
			array(
				'name' => 'Board 6',
				'show_in_boards' => true,
				'show_in_kb' => true,
			),
			// Board 7: Visible only in Boards.
			array(
				'name' => 'Board 7',
				'show_in_boards' => true,
				'show_in_kb' => false,
			),
			// Board 8: Visible only in KB.
			array(
				'name' => 'Board 8',
				'show_in_boards' => false,
				'show_in_kb' => true,
			),
			// Board 9: Visible in both.
			array(
				'name' => 'Board 9',
				'show_in_boards' => true,
				'show_in_kb' => true,
			),
		);

		foreach ( $visibility_settings as $board_config ) {
			$term_name = $board_config['name'];
			$term_slug = sanitize_title( $term_name );
			$term_color = $this->generate_random_color();
			$show_in_boards = $board_config['show_in_boards'];
			$show_in_kb = $board_config['show_in_kb'];

			// Check if the board already exists.
			$existing_term = term_exists( $term_slug, 'resolate_board' );
			if ( $existing_term ) {
				// Update visibility settings for existing board.
				update_term_meta( $existing_term['term_id'], 'term-show-in-boards', $show_in_boards ? '1' : '0' );
				update_term_meta( $existing_term['term_id'], 'term-show-in-kb', $show_in_kb ? '1' : '0' );
				$boards[] = $existing_term['term_id'];
				continue;
			}

			$term = wp_insert_term(
				$term_name,
				'resolate_board',
				array(
					'slug' => $term_slug,
				)
			);

			if ( ! is_wp_error( $term ) ) {
				add_term_meta( $term['term_id'], 'term-color', $term_color, true );
				add_term_meta( $term['term_id'], 'term-show-in-boards', $show_in_boards ? '1' : '0', true );
				add_term_meta( $term['term_id'], 'term-show-in-kb', $show_in_kb ? '1' : '0', true );
				$boards[] = $term['term_id'];
			}
		}
		return $boards;
	}

	/**
	 * Creates sample tasks for each board.
	 *
	 * @param array $labels Array of label term IDs.
	 */
	private function create_kb_articles( $labels ) {
		$lorem_ipsum = array(
			'short' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
			'medium' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
			'long' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
		);

		// Get boards that are visible in KB.
		$kb_boards = get_terms(
			array(
				'taxonomy' => 'resolate_board',
				'hide_empty' => false,
				'meta_query' => array(
					array(
						'key' => 'term-show-in-kb',
						'value' => '1',
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $kb_boards ) ) {
			return;
		}

			// Create main categories; include deeper hierarchy for demo (3+ levels).
			$categories = array(
				'Getting Started' => array(
					'Introduction' => $lorem_ipsum['medium'],
					'Quick Start Guide' => $lorem_ipsum['long'],
					'Basic Concepts' => $lorem_ipsum['medium'],
				),
				'User Guide' => array(
					'Dashboard Overview' => $lorem_ipsum['medium'],
					'Managing Tasks' => array(
						'Creating Tasks' => $lorem_ipsum['short'],
						'Editing Tasks' => array(
							'Basic Edits' => $lorem_ipsum['medium'],
							'Advanced Edits' => array(
								'Keyboard Shortcuts' => $lorem_ipsum['short'],
								'Bulk Changes' => $lorem_ipsum['short'],
							),
						),
						'Deleting Tasks' => $lorem_ipsum['short'],
					),
					'Working with Boards' => array(
						'Board Setup' => $lorem_ipsum['medium'],
						'Managing Columns' => $lorem_ipsum['long'],
					),
				),
				'Advanced Features' => array(
					'API Integration' => array(
						'Authentication' => $lorem_ipsum['medium'],
						'Endpoints' => array(
							'GET /tasks' => $lorem_ipsum['short'],
							'POST /tasks' => $lorem_ipsum['short'],
						),
					),
					'Custom Workflows' => $lorem_ipsum['medium'],
					'Automation Rules' => $lorem_ipsum['medium'],
				),
			);

			// Create articles for each KB-visible board.
			foreach ( $kb_boards as $board_term ) {
				// For each board, create a set of articles.
				foreach ( $categories as $main_title => $subcategories ) {
					// Create main category article (no board suffix in title).
					$main_post_id = wp_insert_post(
						array(
							'post_type' => 'resolate_kb',
							'post_title' => $main_title,
							'post_content' => $lorem_ipsum['short'],
							'post_status' => 'publish',
							'menu_order' => 0,
						)
					);

					// Assign random labels (1-2) to main category.
					$main_labels = $this->wp_rand_elements( $labels, $this->custom_rand( 1, 2 ) );
					wp_set_object_terms( $main_post_id, $main_labels, 'resolate_label' );

					// Assign the board.
					wp_set_object_terms( $main_post_id, array( $board_term->term_id ), 'resolate_board' );

					$order = 0;
					foreach ( $subcategories as $sub_title => $content ) {
						if ( is_array( $content ) ) {
							// This is a subcategory with its own children.
							$sub_post_id = wp_insert_post(
								array(
									'post_type' => 'resolate_kb',
									'post_title' => $sub_title,
									'post_content' => $lorem_ipsum['medium'],
									'post_status' => 'publish',
									'post_parent' => $main_post_id,
									'menu_order' => $order,
								)
							);

							// Assign random labels to subcategory.
							$sub_labels = $this->wp_rand_elements( $labels, $this->custom_rand( 1, 2 ) );
							wp_set_object_terms( $sub_post_id, $sub_labels, 'resolate_label' );

							// Assign the same board as parent.
							wp_set_object_terms( $sub_post_id, array( $board_term->term_id ), 'resolate_board' );

							$sub_order = 0;
							foreach ( $content as $child_title => $child_content ) {
								if ( is_array( $child_content ) ) {
									// Child branch with grandchildren.
									$child_post_id = wp_insert_post(
										array(
											'post_type'    => 'resolate_kb',
											'post_title'   => $child_title,
											'post_content' => $lorem_ipsum['medium'],
											'post_status'  => 'publish',
											'post_parent'  => $sub_post_id,
											'menu_order'   => $sub_order,
										)
									);

									$child_labels = $this->wp_rand_elements( $labels, $this->custom_rand( 1, 2 ) );
									wp_set_object_terms( $child_post_id, $child_labels, 'resolate_label' );
									wp_set_object_terms( $child_post_id, array( $board_term->term_id ), 'resolate_board' );

									$gg_order = 0;
									foreach ( $child_content as $g_title => $g_content ) {
												$gc_post_id = wp_insert_post(
													array(
														'post_type'    => 'resolate_kb',
														'post_title'   => $g_title,
														'post_content' => is_array( $g_content ) ? $lorem_ipsum['short'] : $g_content,
														'post_status'  => 'publish',
														'post_parent'  => $child_post_id,
														'menu_order'   => $gg_order,
													)
												);
												$gc_labels = $this->wp_rand_elements( $labels, $this->custom_rand( 1, 2 ) );
												wp_set_object_terms( $gc_post_id, $gc_labels, 'resolate_label' );
												wp_set_object_terms( $gc_post_id, array( $board_term->term_id ), 'resolate_board' );
												$gg_order++;
									}
									$sub_order++;
								} else {
									// Leaf child.
									$child_post_id = wp_insert_post(
										array(
											'post_type'    => 'resolate_kb',
											'post_title'   => $child_title,
											'post_content' => $child_content,
											'post_status'  => 'publish',
											'post_parent'  => $sub_post_id,
											'menu_order'   => $sub_order,
										)
									);

									// Assign random labels to child and same board.
									$child_labels = $this->wp_rand_elements( $labels, $this->custom_rand( 1, 2 ) );
									wp_set_object_terms( $child_post_id, $child_labels, 'resolate_label' );
									wp_set_object_terms( $child_post_id, array( $board_term->term_id ), 'resolate_board' );

									$sub_order++;
								}
							}
						} else {
							// This is a direct subcategory.
							$sub_post_id = wp_insert_post(
								array(
									'post_type' => 'resolate_kb',
									'post_title' => $sub_title,
									'post_content' => $content,
									'post_status' => 'publish',
									'post_parent' => $main_post_id,
									'menu_order' => $order,
								)
							);

							// Assign random labels to subcategory.
							$sub_labels = $this->wp_rand_elements( $labels, $this->custom_rand( 1, 2 ) );
							wp_set_object_terms( $sub_post_id, $sub_labels, 'resolate_label' );

							// Assign the same board as parent.
							wp_set_object_terms( $sub_post_id, array( $board_term->term_id ), 'resolate_board' );
						}
						$order++;
					}
				}
			}
	}

	/**
	 * Creates demo events for the current and previous month.
	 *
	 * This method generates events with random titles, categories, locations,
	 * and assigned users. Events can be all-day or have specific time slots.
	 */
	private function create_events() {
		$event_categories = array( 'bg-success', 'bg-info', 'bg-warning' );
		$event_titles = array(
			__( 'Team Meeting', 'resolate' ),
			__( 'Project Review', 'resolate' ),
			__( 'Training Session', 'resolate' ),
			__( 'Client Presentation', 'resolate' ),
			__( 'Sprint Planning', 'resolate' ),
			__( 'Code Review', 'resolate' ),
			__( 'Release Day', 'resolate' ),
			__( 'Maintenance Window', 'resolate' ),
		);

		$event_urls = array(
			'https://site1.example.com',
			'https://site2.example.com',
			'https://wikipedia.org',
		);

		$event_locations = array(
			__( 'Meeting Room A', 'resolate' ),
			__( 'Conference Room', 'resolate' ),
			__( 'Training Center', 'resolate' ),
			__( 'Virtual Meeting', 'resolate' ),
			__( 'Main Office', 'resolate' ),
		);

		// Get all users for random assignment.
		$users = get_users( array( 'fields' => array( 'ID' ) ) );
		$user_ids = wp_list_pluck( $users, 'ID' );

		// Create events for current month.
		$current_month_start = new DateTime( 'first day of this month' );
		$current_month_end = new DateTime( 'last day of this month' );
		$this->generate_month_events( $current_month_start, $current_month_end, $event_titles, $event_categories, $event_urls, $event_locations, $user_ids );

		// Create events for previous month.
		$prev_month_start = new DateTime( 'first day of last month' );
		$prev_month_end = new DateTime( 'last day of last month' );
		$this->generate_month_events( $prev_month_start, $prev_month_end, $event_titles, $event_categories, $event_urls, $event_locations, $user_ids );
	}

	/**
	 * Generates events for a specific month.
	 *
	 * This method creates a random number of events within the given date range.
	 * Each event has a randomly assigned title, category, location, time slot,
	 * and assigned users.
	 *
	 * @param DateTime $start_date Start date of the month.
	 * @param DateTime $end_date   End date of the month.
	 * @param array    $event_titles Array of possible event titles.
	 * @param array    $event_categories Array of possible event categories.
	 * @param array    $event_urls Array of possible event urls.
	 * @param array    $event_locations Array of possible event locations.
	 * @param array    $user_ids Array of user IDs for assignment.
	 */
	private function generate_month_events( $start_date, $end_date, $event_titles, $event_categories, $event_urls, $event_locations, $user_ids ) {
		$num_events = $this->custom_rand( 5, 10 ); // 5-10 events per month.

		for ( $i = 0; $i < $num_events; $i++ ) {
			// Random date within the month.
			$event_date = clone $start_date;
			$interval = $start_date->diff( $end_date )->days;
			$event_date->modify( '+' . $this->custom_rand( 0, $interval ) . ' days' );

			// 50% chance of all-day event.
			$is_all_day = $this->random_boolean( 0.5 );

			if ( ! $is_all_day ) {
				// For non-all-day events, set random time between 9 AM and 5 PM.
				$hour = $this->custom_rand( 9, 17 );
				$minute = $this->custom_rand( 0, 3 ) * 15; // 0, 15, 30, or 45.
				$event_date->setTime( $hour, $minute );

				// Duration between 30 minutes and 3 hours.
				$duration_minutes = $this->custom_rand( 1, 6 ) * 30;
				$end_date = clone $event_date;
				$end_date->modify( "+{$duration_minutes} minutes" );
			} else {
				$end_date = clone $event_date;
				// All-day events might span 1-3 days.
				$end_date->modify( '+' . $this->custom_rand( 0, 2 ) . ' days' );
			}

			// Create the event.
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'resolate_event',
					'post_title'  => $event_titles[ array_rand( $event_titles ) ],
					'post_content' => __( 'Demo event created automatically.', 'resolate' ),
					'post_status' => 'publish',
				)
			);

			if ( ! is_wp_error( $post_id ) ) {
				// Prepare data as expected in process_and_save_meta().
				$data = array(
					'event_allday'         => $is_all_day,
					'event_start'          => $event_date->format( $is_all_day ? 'Y-m-d' : 'Y-m-d H:i:s' ),
					'event_end'            => $end_date->format( $is_all_day ? 'Y-m-d' : 'Y-m-d H:i:s' ),
					'event_location'       => $event_locations[ array_rand( $event_locations ) ],
					'event_url'            => $event_urls[ array_rand( $event_urls ) ],
					'event_category'       => $event_categories[ array_rand( $event_categories ) ],
					// Assign 1-3 random users.
					'event_assigned_users' => $this->wp_rand_elements( $user_ids, $this->custom_rand( 1, 3 ) ),
				);

				// Save the metadaa.
				$events_handler = new Resolate_Events();
				$events_handler->process_and_save_meta( $post_id, $data );
			}
		}
	}

	/**
	 * Creates sample tasks for each board.
	 *
	 * This method generates tasks with random labels, assigned users, priority,
	 * due dates, and other attributes, associating them with specific boards.
	 * Only creates tasks for boards that are visible in the Boards section.
	 *
	 * @param array $boards Array of board term IDs.
	 * @param array $labels Array of label term IDs.
	 */
	private function create_tasks( $boards, $labels ) {
		$users = get_users( array( 'fields' => array( 'ID' ) ) );
		if ( empty( $users ) ) {
			return;
		}
		$user_ids = wp_list_pluck( $users, 'ID' );

		// Get boards that are visible in Boards section.
		$visible_boards = get_terms(
			array(
				'taxonomy' => 'resolate_board',
				'hide_empty' => false,
				'meta_query' => array(
					array(
						'key' => 'term-show-in-boards',
						'value' => '1',
						'compare' => '=',
					),
				),
			)
		);

		$visible_board_ids = wp_list_pluck( $visible_boards, 'term_id' );

		foreach ( $boards as $board_id ) {
			$board = get_term( $board_id, 'resolate_board' );
			if ( is_wp_error( $board ) ) {
				continue;
			}

			// Check if this board is visible in Boards section.
			$show_in_boards = get_term_meta( $board_id, 'term-show-in-boards', true );

			// Depending on board visibility, the number of tasks to create is set.
			if ( '1' === $show_in_boards ) {
				$num_tasks = 10;
			} else {
				$num_tasks = 3; // Fewer tasks are created if the board is hidden.
			}

			for ( $j = 1; $j <= $num_tasks; $j++ ) {
				$post_title = "Task $j for {$board->name}";
				$post_content = "Content for task $j in board {$board->name}.";

				if ( '1' !== $show_in_boards ) {
					$post_title .= ' (Hidden Board)';
				}

				// Assign random labels (0 to 3 labels).
				$num_labels = $this->custom_rand( 0, 3 );
				$assigned_labels = ( $num_labels > 0 && ! empty( $labels ) )
					? $this->wp_rand_elements( $labels, $num_labels )
					: array();

				// Assign random users (1 to 3 users).
				$num_users = $this->custom_rand( 1, 3 );
				$assigned_users = $this->wp_rand_elements( $user_ids, $num_users );

				// Generate additional fields.
				$max_priority = $this->random_boolean( 0.2 );
				$archived = $this->random_boolean( 0.2 );
				$creation_date = $this->random_date( '-2 months', 'now' );
				$start_date = $this->random_date( '-2 months', 'now' );
				$duration = $this->custom_rand( 1, 14 );
				$end_date = clone $start_date;
				$end_date->modify( "+{$duration} days" );
				$stack = $this->random_stack();

				$task_id = Resolate_Tasks::create_or_update_task(
					0,
					$post_title,
					$post_content,
					$stack,
					$board_id,
					$max_priority,
					$end_date, // due date is end of task.
					1,
					1,
					false,
					$assigned_users,
					$assigned_labels,
					$creation_date,
					$archived,
					0
				);

				if ( $task_id && ! is_wp_error( $task_id ) ) {
					// Generate user-date relations for each day in the task duration.
					$relations = array();
					$period_start = clone $start_date;
					$period_end = clone $end_date;
					$period_end->modify( '+1 day' ); // to include end date.

					$interval = new DateInterval( 'P1D' );
					$period = new DatePeriod( $period_start, $interval, $period_end );

					foreach ( $period as $day ) {
						foreach ( $assigned_users as $user_id ) {
							$dates = iterator_to_array( $period );
							$days_to_assign = $this->custom_rand( 1, count( $dates ) );
							$random_dates = $this->wp_rand_elements( $dates, $days_to_assign );

							foreach ( $random_dates as $day ) {
								$relations[] = array(
									'user_id' => $user_id,
									'date'    => $day->format( 'Y-m-d' ),
								);
							}
						}
					}

					update_post_meta( $task_id, '_user_date_relations', $relations );
					update_post_meta( $task_id, 'startdate', $start_date->format( 'Y-m-d' ) );
				}
			}
		}
	}

	/**
	 * Generates a random hexadecimal color.
	 *
	 * @return string Color in hexadecimal format (e.g., #a3f4c1).
	 */
	private function generate_random_color() {
		return sprintf( '#%06X', $this->custom_rand( 0, 0xFFFFFF ) );
	}

	/**
	 * Selects random elements from an array.
	 *
	 * @param array $array Array to select elements from.
	 * @param int   $number Number of elements to select.
	 * @return array Selected elements.
	 */
	private function wp_rand_elements( $array, $number ) {
		if ( $number >= count( $array ) ) {
			return $array;
		}
		$keys = array_rand( $array, $number );
		if ( 1 == $number ) {
			return array( $array[ $keys ] );
		}
		$selected = array();
		foreach ( $keys as $key ) {
			$selected[] = $array[ $key ];
		}
		return $selected;
	}

	/**
	 * Generates a random boolean value based on a probability.
	 *
	 * @param float $true_probability Probability of returning true (between 0 and 1).
	 * @return bool
	 */
	private function random_boolean( $true_probability = 0.5 ) {
		return ( $this->custom_rand() / mt_getrandmax() ) < $true_probability;
	}

	/**
	 * Generates a random date between two given dates.
	 *
	 * @param string $start Start date (format recognized by strtotime).
	 * @param string $end End date (format recognized by strtotime).
	 * @return DateTime Randomly generated date.
	 */
	private function random_date( $start, $end ) {
		$min = strtotime( $start );
		$max = strtotime( $end );
		$timestamp = $this->custom_rand( $min, $max );
		return ( new DateTime() )->setTimestamp( $timestamp );
	}

	/**
	 * Selects a random stack.
	 *
	 * @return string One of these values: 'to-do', 'in-progress', 'done'.
	 */
	private function random_stack() {
		$stacks = array( 'to-do', 'in-progress', 'done' );
		return $stacks[ array_rand( $stacks ) ];
	}

	/**
	 * Custom random number generator for WordPress Playground.
	 *
	 * @param int $min Minimum value.
	 * @param int $max Maximum value.
	 * @return int Random number between $min and $max.
	 */
	private function custom_rand( $min = 0, $max = PHP_INT_MAX ) {

		return wp_rand( $min, $max );
	}
}
