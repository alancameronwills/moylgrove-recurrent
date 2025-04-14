<?php

/**
 * @package Moylgrove Recurrent Events
 * @version 1.2
 */
/*
Plugin Name: Moylgrove Recurrent Events
Description: Resets an expired event post to a future date
Author: Alan Wills
Version: 1.2
*/

function moylgrove_recurrent_events_shortcode($attributes = [])
{
    extract(shortcode_atts(
        [
            'asIfDate' => '',
            'category' => 'event',
            'doIt' => true,
        ],
        $attributes
    ));
    moylgrove_reschedule_events($category, true, $asIfDate, $doIt);
}

add_shortcode("recurrence", "moylgrove_recurrent_events_shortcode");


function moylgrove_recurrence_install()
{
    if (!wp_next_scheduled('moylgrove_recurrence_cron_hook')) {
        wp_schedule_event(strtotime( 'tomorrow 02:18' ), 'daily', 'moylgrove_recurrence_cron_hook');
    }

    moylgrove_recurrence_cron_exec();
}

add_action("moylgrove_recurrence_cron_hook", "moylgrove_recurrence_cron_exec");

function moylgrove_recurrence_deactivate()
{
	wp_clear_scheduled_hook('moylgrove_recurrence_cron_hook');
}

function moylgrove_recurrence_uninstall() {
}

register_activation_hook(__FILE__, 'moylgrove_recurrence_install');
register_deactivation_hook(__FILE__, 'moylgrove_recurrence_deactivate');
register_uninstall_hook(__FILE__, 'moylgrove_recurrence_uninstall');

function moylgrove_recurrence_cron_exec()
{
    moylgrove_reschedule_events('event', false, '', true);
}

function moylgrove_reschedule_events($category, $generateOutput = false, $asIfDate = '', $doIt = true)
{
    error_log("moylgrove_reschedule_events");
    // Get posts from database
    $query = [
        'category_name' => $category,
        'order' => "DESC",
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'expires',
                'compare' => 'EXISTS'
            ],
            [
                'key' => 'expires',
                'value' => date('Y-m-d'),
                'compare' => '<',
                'type' => 'DATE'
            ],
            [
                'key' => 'recurrence',
                'compare' => 'EXISTS'
            ]
        ]
    ];
    if ($generateOutput) {
?>
        <table>
            <tr>
                <th>Title</th>
                <th>Expires</th>
                <th>Starts</th>
                <th>D</th>
                <th>W</th>
                <th>Next</th>
            </tr>
            <?php
        }
        try {
            moylgrove_recurrence_set_fields();
            $posts = new WP_Query($query);
            while ($posts->have_posts()):
                $posts->the_post();
                $title = get_the_title();
                $id = get_the_ID();
                $recurrence = get_field("recurrence");
                if (isset($recurrence, $recurrence['day'], $recurrence['weeks']) && $recurrence['day'] > 0 && count($recurrence['weeks']) > 0):
                    $nextDate = nthDayOfMonth($recurrence['day'], $recurrence['weeks'], NULL);
                    $dtstart = get_field("dtstart");
                    $expires = get_field("expires");
                    $newExpires = $nextDate->format("Y-m-d");
                    $newStart = $newExpires . " " . substr($dtstart, 10);

                    if ($generateOutput) {
            ?>
                        <tr>
                            <td><a href="<?= get_page_link() ?>"><?= $title ?></a></td>
                            <td><?= $expires ?></td>
                            <td><?= $dtstart ?></td>
                            <td><?= $recurrence['day'] ?></td>
                            <td><?= implode(", ", $recurrence['weeks']) ?></td>
                            <td><?= $nextDate->format("l Y M d") ?></td>
                        </tr>
                        <tr>
                            <td></td>
                            <td><?= $newExpires ?></td>
                            <td><?= $newStart ?></td>
                        </tr>

                        <tr>
                            <td colspan=5>
                                <?php
                                $fields = get_fields();
                                ?>
                                <ul>
                                    <?php foreach ($fields as $name => $value): ?>
                                        <li><b><?php echo $name; ?></b> <?php print_r($value); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>

                        </tr>
            <?php
                    }
                    error_log("  Moylgrove recurrence Start: $newStart Expires: $newExpires  '$title'");
                    if ($doIt) {
                        update_post_meta($id, "dtstart", $newStart);
				        update_post_meta($id, "expires", $newExpires);
                    }
                endif;
            endwhile;
        } catch (Exception $e) {
            error_log("  Moylgrove recurrence: " . print_r($e, true));
        }

        if ($generateOutput) {
            ?>
        </table>
<?php
        }
		$next_run = wp_next_scheduled('moylgrove_recurrence_cron_hook');
		error_Log("  Moylgrove recurrence next run: " . wp_date('r', $next_run));
    }

    
function nthDayOfMonth($dayOfWeek, $weeksInMonth, $today)
{
	if (!$today) {
		$today = new DateTime('NOW');
	}
	$result = NULL;
	$current_month = $today->format("n") + 0;
	$current_date = $today->format("d") + 0;

	// First day of current month
	$diff = $current_date - 1;
	$dt = clone $today;
	$dt->sub(new DateInterval("P{$diff}D"));
	//echo "First of current month: {$dt->format("l Y M d")}\n";

	// First required day of current month
	$focm = $dt->format("N") + 0;
	//echo "First day of current month is $focm\n";
	$freqocm = ($dayOfWeek - $focm + 7) % 7;
	//echo "First required day of current month is {$freqocm}\n";
	$dt->add(new DateInterval("P{$freqocm}D"));

	$monthCount = 0;
	$weekCount = 0;
	$currentMonth = 0;
	$checkWeek = 0;
	$result = NULL;
	for ($i = 0; $i < 10; $i++) {
		$weekCount++;
		$newMonth = $dt->format("n") + 0;
		if ($currentMonth != $newMonth) {
			$monthCount++;
			$currentMonth = $newMonth;
			$weekCount = 1;
			$checkWeek = 0;
		}
		$later = $dt >= $today;
		$found = $weeksInMonth[$checkWeek] == $weekCount;
		if (!$found && $weeksInMonth[$checkWeek] == 5 && $weekCount == 4) {
			$nextWeek = clone $dt;
			$nextWeek->add(new DateInterval("P7D"));
			$nextWeekMonth = $nextWeek->format("n") + 0;
			$found = $nextWeekMonth != $currentMonth;
		}

		//echo "$weekCount = {$dt->format("l Y M d")} $later  $found\n";
		if ($result == NULL && $later && $found) {
			$result = clone $dt;
			//return $result;
		}

		if (
			$weeksInMonth[$checkWeek] <= $weekCount
			&& $checkWeek < count($weeksInMonth)-1
		) $checkWeek++;
		$dt->add(new DateInterval("P7D"));
	}
	return $result;
}

$moylgrove_recurrence_custom_field_group_key = 'group_67ebda3144930';

function moylgrove_recurrence_set_fields() {
	global $moylgrove_recurrence_custom_field_group_key;
	//error_log("moylgrove_recurrence_set_fields 1 " . $moylgrove_recurrence_custom_field_group_key);
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}
	//error_log("moylgrove_recurrence_set_fields 2");

	acf_add_local_field_group( array(
	'key' => $moylgrove_recurrence_custom_field_group_key,
	'title' => 'Recurrence',
	'fields' => array(
		array(
			'key' => 'field_67ebda3147b37',
			'label' => 'Recurrence',
			'name' => 'recurrence',
			'aria-label' => '',
			'type' => 'group',
			'instructions' => 'Use this for events that occur regularly, and you want them to appear in the main part of the calendar. (Best for events that don\'t occur every week.)
When an event expires, a new one will automatically appear with the same title, time of day, etc.
If you reschedule an event to another date, the next will appear on the usual day
If you want to cancel a specific event, move it to the next scheduled date',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'layout' => 'block',
			'sub_fields' => array(
				array(
					'key' => 'field_67ebda3151af4',
					'label' => 'Usual day of week',
					'name' => 'day',
					'aria-label' => '',
					'type' => 'select',
					'instructions' => '',
					'required' => 0,
					'conditional_logic' => 0,
					'wrapper' => array(
						'width' => '',
						'class' => '',
						'id' => '',
					),
					'choices' => array(
						0 => '--none--',
						1 => 'Monday',
						2 => 'Tuesday',
						3 => 'Wednesday',
						4 => 'Thursday',
						5 => 'Friday',
						6 => 'Saturday',
						7 => 'Sunday',
					),
					'default_value' => 0,
					'return_format' => 'value',
					'multiple' => 0,
					'allow_null' => 0,
					'allow_in_bindings' => 0,
					'ui' => 0,
					'ajax' => 0,
					'placeholder' => '',
				),
				array(
					'key' => 'field_67ebda3151b2e',
					'label' => 'Weeks of month',
					'name' => 'weeks',
					'aria-label' => '',
					'type' => 'checkbox',
					'instructions' => 'Which weeks you want the event to appear',
					'required' => 0,
					'conditional_logic' => 0,
					'wrapper' => array(
						'width' => '',
						'class' => '',
						'id' => '',
					),
					'choices' => array(
						1 => '1st',
						2 => '2nd',
						3 => '3rd',
						4 => '4th',
						5 => 'last',
					),
					'default_value' => array(
					),
					'return_format' => 'value',
					'allow_custom' => 0,
					'allow_in_bindings' => 0,
					'layout' => 'horizontal',
					'toggle' => 0,
					'save_custom' => 0,
					'custom_choice_button_text' => 'Add new choice',
				),
			),
		),
	),
	'location' => array(
		array(
			array(
				'param' => 'post_type',
				'operator' => '==',
				'value' => 'post',
			),
			array(
				'param' => 'post_category',
				'operator' => '==',
				'value' => 'category:event',
			),
		),
	),
	'menu_order' => 1,
	'position' => 'normal',
	'style' => 'seamless',
	'label_placement' => 'top',
	'instruction_placement' => 'label',
	'hide_on_screen' => array(
		0 => 'excerpt',
		1 => 'discussion',
		2 => 'comments',
		3 => 'send-trackbacks',
	),
	'active' => true,
	'description' => '',
	'show_in_rest' => 0,
) );
}

add_action( 'acf/include_fields', 'moylgrove_recurrence_set_fields');

?>