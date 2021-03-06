<?php

class quick_edit_grade extends quick_edit_tablelike
    implements selectable_items, item_filtering {

    private $requires_extra = false;

    private $requires_paging = false;

    var $structure;

    private static $allow_categories;

    private $suspendedusers = array();

    public static function allow_categories() {
        if (is_null(self::$allow_categories)) {
            self::$allow_categories = get_config('moodle', 'grade_overridecat');
        }

        return self::$allow_categories;
    }

    public static function filter($item) {
        return (
            self::allow_categories() or !(
                $item->is_course_item() or $item->is_category_item()
            )
        );
    }

    public function description() {
        return get_string('users');
    }

    public function options() {
        return array_map(function($user) { 
            if (!empty($user->alternatename)) {
                return $user->alternatename . ' (' . $user->firstname . ') ' . $user->lastname;
            } else {
                return fullname($user);
            } 
        }, $this->items); 
    }

    public function item_type() {
        return 'user';
    }

    public function original_definition() {
        $def = array('finalgrade', 'feedback');

        if ($this->requires_extra) {
            $def[] = 'override';
        }

        $def[] = 'exclude';

        return $def;
    }

    public function init($self_item_is_empty = false) {
        $roleids = explode(',', get_config('moodle', 'gradebookroles'));

        $this->items = get_role_users(
            $roleids, $this->context, false, '',
            'u.lastname, u.firstname', null, $this->groupid
        );

        if ($self_item_is_empty) {
            return;
        }

        // Only page when necessary
        if (count($this->items) > $this->perpage) {
            $this->requires_paging = true;

            $this->all_items = $this->items;

            $this->items = get_role_users(
                $roleids, $this->context, false, '',
                'u.lastname, u.firstname', null, $this->groupid,
                $this->perpage * $this->page, $this->perpage
            );
        }

        global $CFG, $DB;

        $params = array(
            'id' => $this->itemid,
            'courseid' => $this->courseid
        );

        $this->item = grade_item::fetch($params);

        $filter_fun = grade_report_quick_edit::filters();

        $allowed = $filter_fun($this->item);

        if (empty($allowed)) {
            print_error('not_allowed', 'gradereport_quick_edit');
        }

        $this->requires_extra = !$this->item->is_manual_item();

        $this->setup_structure();

        $this->set_definition($this->original_definition());
        $this->set_headers($this->original_headers());

        // Filter out suspended users.
        $defaultgradeshowactiveenrol = !empty($CFG->grade_report_showonlyactiveenrol);
        $showonlyactiveenrol = get_user_preferences('grade_report_showonlyactiveenrol', $defaultgradeshowactiveenrol);
        $showonlyactiveenrol = $showonlyactiveenrol || !has_capability('moodle/course:viewsuspendedusers', $this->context);
        // Save suspended users for display later.
        $this->suspendedusers = get_suspended_userids($this->context, true);
        if ($showonlyactiveenrol) {
            // User does not want to or cannot see suspended users.
            foreach ($this->items as $userid => $item) {
                if (in_array($userid, $this->suspendedusers)) {
                    unset($this->items[$userid]);
                }
            }
        }
    }

    public function original_headers() {
        $headers = array(
            '',
            get_string('firstname') . ' (' . get_string('alternatename') . ') ' . get_string('lastname'),
            get_string('range', 'grades'),
            get_string('grade', 'grades'),
            get_string('feedback', 'grades')
        );

        return $this->additional_headers($headers);
    }

    public function format_line($item) {
        global $OUTPUT;

        $grade = $this->fetch_grade_or_default($this->item, $item->id);

        // UCSB add lock icon indicator
        $lockicon = '';

        // CODE to make steve happy for his simple mind
	$locked_grade = $locked_grade_item = 0;
        if ( ! empty($grade->locked) )  $locked_grade = 1;
        if ( ! empty($grade->grade_item->locked) ) $locked_grade_item = 1;
        if ( $locked_grade || $locked_grade_item )  // check both grade and grade item
            $lockicon = $OUTPUT->pix_icon('t/locked', 'grade is locked') . ' ';

        if (!empty($item->alternatename)) {
            $fullname = $lockicon . $item->alternatename . ' (' . $item->firstname . ') ' . $item->lastname;
        } else {
            $fullname = $lockicon . fullname($item);
        }

        // Display suspended user icon, if applicable.
        if (in_array($item->id, $this->suspendedusers)) {
            $suspendedstring = get_string('userenrolmentsuspended', 'grades');
            $suspendedicon = ' ' . html_writer::empty_tag('img',
                    array('src' => $OUTPUT->pix_url('i/enrolmentsuspended'),
                        'title' => $suspendedstring, 'alt' => $suspendedstring,
                        'class' => 'usersuspendedicon'));
            $fullname .= $suspendedicon;
        }

        $item->imagealt = $fullname;

        $line = array(
            $OUTPUT->user_picture($item),
            $this->format_link('user', $item->id, $fullname),
            $this->item_range()
        );

        return $this->format_definition($line, $grade);
    }

    public function additional_headers($headers) {
        if ($this->requires_extra) {
            $headers[] = $this->make_toggle_links('override');
        }

        $headers[] = $this->make_toggle_links('exclude');

        return $headers;
    }

    public function item_range() {
        if (empty($this->range)) {
            $this->range = $this->factory()->create('range')->format($this->item);
        }

        return $this->range;
    }

    public function supports_paging() {
        return $this->requires_paging;
    }

    public function pager() {
        global $OUTPUT;

        return $OUTPUT->paging_bar(
            count($this->all_items), $this->page, $this->perpage,
            new moodle_url('/grade/report/quick_edit/index.php', array(
                'perpage' => $this->perpage,
                'id' => $this->courseid,
                'group' => $this->groupid,
                'itemid' => $this->itemid,
                'item' => 'grade'
            ))
        );
    }

    public function heading() {
        return $this->item->get_name();
    }
}
