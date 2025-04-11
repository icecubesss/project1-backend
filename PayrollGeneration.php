<?php

namespace Resources\JuanHR\Helpers;

use Core\Security\Logger;
use Core\System\Messages;
use DateInterval;
use DatePeriod;
use DateTime;
use Resources\JuanHR\Helpers\BranchScheduleHelper as BranchScheduleHelper;
use Resources\JuanHR\Helpers\DateTimeHelper;
use Resources\JuanHR\Helpers\DtrLogGetter as DtrLogGetter;
use Resources\JuanHR\Helpers\LeaveHelper;
use Resources\JuanHR\Helpers\TaxHelper;

class PayrollGeneration
{
    /**
     * @var Core\System\Messages
     */
    public Messages $Messages;

    public $db;
    public $memos;
    public $params;
    public $holidays;
    public $benefits;
    public $employee_benefits;
    public $employee_allowances;
    public $employee_loans;
    public $employee_adjustments;
    public $employee_overtime;
    public $employee_leaves;
    public $employee_dtr;
    public $employee_branches;
    public $employee_schedule;
    public $employee_service_records;
    public $configuration;
    public $Logger;

    private $schedules;

    private $leave_helper;

    /**
     * PayrollGeneration constructor.
     */
    public function __construct($parent, $params)
    {
        $this->db = $parent->db;
        $this->params = $params;
        $this->Logger = new Logger;
        $this->leave_helper = new LeaveHelper($this);
        $this->setConfiguration();
        $this->configuration['night_diff_configuration'] = array('night_diff_start' => '22:00:00', 'night_diff_end' => '6:00:00');
    }

    public function setConfiguration()
    {
        $queryResult = $this->db->get('config_payroll as configPayroll', null, 'configPayroll.*');

        foreach ($queryResult as $setConfig) {
            $this->configuration['payroll_config'][$setConfig['payroll_configuration_id']] = [
                'payroll_configuration_id' => $setConfig['payroll_configuration_id'],
                'branch_id' => $setConfig['branch_id'],
                'combine_late_periods' => $setConfig['combine_late_periods'],
                'late_rules' => $setConfig['late_rules'],
                'undertime_rules' => $setConfig['undertime_rules'],
                'overbreak_rules' => $setConfig['overbreak_rules'],
                'enable_night_diff' => $setConfig['enable_night_diff'],
                'night_diff_rate' => $setConfig['night_diff_rate'],
                'regular_overtime_percentage' => $setConfig['regular_overtime_percentage'],
                'regular_overtime_excess_percentage' => $setConfig['regular_overtime_excess_percentage'],
                'regular_overtime_night_diff_percentage' => $setConfig['regular_overtime_night_diff_percentage'],
                'rest_day_percentage' => $setConfig['rest_day_percentage'],
                'rest_day_excess_percentage' => $setConfig['rest_day_excess_percentage'],
                'rest_day_night_diff_percentage' => $setConfig['rest_day_night_diff_percentage'],
                'regular_holiday_percentage' => $setConfig['regular_holiday_percentage'],
                'regular_holiday_excess_percentage' => $setConfig['regular_holiday_excess_percentage'],
                'regular_holiday_overtime_percentage' => $setConfig['regular_holiday_overtime_percentage'],
                'regular_holiday_night_diff_percentage' => $setConfig['regular_holiday_night_diff_percentage'],
                'special_holiday_percentage' => $setConfig['special_holiday_percentage'],
                'special_holiday_excess_percentage' => $setConfig['special_holiday_excess_percentage'],
                'special_holiday_night_diff_percentage' => $setConfig['special_holiday_night_diff_percentage'],
                'special_holiday_overtime_percentage' => $setConfig['special_holiday_overtime_percentage'],
                'daily_for_monthly_semi_basic_salary_dx' => $setConfig['daily_for_monthly_semi_basic_salary_dx'],
                'daily_for_monthly_semi_basic_salary_dy' => $setConfig['daily_for_monthly_semi_basic_salary_dy'],
                'daily_for_monthly_weekly_basic_salary_dx' => $setConfig['daily_for_monthly_weekly_basic_salary_dx'],
                'daily_for_monthly_weekly_basic_salary_dy' => $setConfig['daily_for_monthly_weekly_basic_salary_dy'],
                'allowance_include_period_absence_for_monthly' => $setConfig['allowance_include_period_absence_for_monthly'],
                'allowance_include_lates_for_monthly' => $setConfig['allowance_include_lates_for_monthly'],
                'allowance_include_undertime_for_monthly' => $setConfig['allowance_include_undertime_for_monthly'],
                'allowance_include_period_absence_for_non_monthly' => $setConfig['allowance_include_period_absence_for_non_monthly'],
                'allowance_include_lates_for_non_monthly' => $setConfig['allowance_include_lates_for_non_monthly'],
                'allowance_include_undertime_for_non_monthly' => $setConfig['allowance_include_undertime_for_non_monthly'],
                'deduct_benefits_for_no_earnings' => $setConfig['deduct_benefits_for_no_earnings'],
                'deduct_loans_for_no_earnings' => $setConfig['deduct_loans_for_no_earnings'],
                'allow_unposted_leave' => $setConfig['allow_unposted_leave'],
                'allow_unposted_overtime' => $setConfig['allow_unposted_overtime'],
                'consider_no_timein_timeout_beetween_logs' => $setConfig['consider_no_timein_timeout_beetween_logs'],
                'as_of_date' => $setConfig['as_of_date'],
                'end_date' => $setConfig['end_date'],
                'created_by' => $setConfig['created_by'],
                'created_date' => $setConfig['created_date'],
                'employment_status' => $setConfig['employment_status'],
                'rates' => $setConfig['rates'],
                'rest_day_special_holiday_percentage' => $setConfig['rest_day_special_holiday_percentage'],
                'rest_day_overtime_percentage' => $setConfig['rest_day_overtime_percentage']
            ];
        }
    }

    /**
     * Get Employee Adjustments
     *
     * @param array $list
     * @param string $month
     * @param string $year
     * @param string $term
     * @return array
     * @throws \Exception
     */
    public function getPayrollAdjustments($list, $month, $year, $term, $payroll)
    {
        $adjustments = array();

        $month_year = "$year-$month";
        try {
            $this->db->where("adjustment.employee_id", $list, 'IN');
            $this->db->where("DATE_FORMAT(adjustment.month_year, '%Y-%c')", $month_year);
            // $this->db->where('adjustment.month_year', $year);
            $this->db->where('adjustment.term', $term);
            $this->db->join('config_adjustment as config', 'config.adjustment_id = adjustment.adjustment_id', 'LEFT');
            $queryResult = $this->db->get('emp_adjustments as adjustment', null, 'config.*,adjustment.*');

            if ($this->db->count > 0) {
                foreach ($queryResult as $employee_adjustments) {
                    // array('total_additionals' => 0, 'total_deductions'=>0,'adjustments' => array());
                    if (isset($payroll[$employee_adjustments['employee_id']]) && ($employee_adjustments['payroll_posted'] === 0 || $employee_adjustments['payroll_posted'] === null)) {
                        /**
                         * Fetch All Necessary Employee Adjustments
                         */
                        $emp_adjustments = array(
                            'adjustment_id' => $employee_adjustments['id'],
                            'type' => $employee_adjustments['type'],
                            'amount' => $employee_adjustments['amount'],
                            'remarks' => empty($employee_adjustments['remarks']) && null,
                            'payroll_id' => $payroll[$employee_adjustments['employee_id']]['payroll_id'],
                            'posted' => 1,
                            'term' => $employee_adjustments['term'],
                            'employee_id' => $employee_adjustments['employee_id']
                        );

                        /**
                         * Insert $emp_adjustments to $adjustments ready for insertion
                         */
                        array_push($adjustments, $emp_adjustments);
                        array_push($payroll[$employee_adjustments['employee_id']]['adjustments']['adjustments'], $emp_adjustments);

                        if ($employee_adjustments['type'] === 0) {
                            $payroll[$employee_adjustments['employee_id']]['adjustments']['total_deductions'] += $employee_adjustments['amount'];
                            $payroll[$employee_adjustments['employee_id']]['total_deductions'] += $employee_adjustments['amount'];
                        } else {
                            $payroll[$employee_adjustments['employee_id']]['adjustments']['total_additionals'] += $employee_adjustments['amount'];
                            $payroll[$employee_adjustments['employee_id']]['total_earnings'] += $employee_adjustments['amount'];
                        }
                    }
                }
            }
            $this->db->insertMulti('pr_adjustments', $adjustments);

            /**
             * Logger
             */
            if ($this->db->getLastErrno() !== 0) {
                $this->Logger->LogError("[ERROR] | InsertMulti [Payroll Generation] On Line: " . __LINE__, $this->db->getLastError());
            }

            if (count($adjustments) > 0) {

                /** temporarily comment-out(Son) */

                /**  UPDATE PAYROLL_POSTED = PAYROLL_ID -  */
                // foreach ($payroll as $el) {
                //     $this->db->where('employee_id', $el['employee_id']);
                //     $this->db->where('DATE_FORMAT(month_year, "%Y-%c")', $month_year);
                //     $this->db->where('term', $term);
                //     $this->db->update('emp_adjustments', ['payroll_posted' => $el['payroll_id']]);
                // }

            }
        } catch (Exception $e) {
            $this->Logger->LogError("[ERROR] | [Payroll Generation] On Line: " . __LINE__, $this->db->getLastError());
            $this->Logger->LogError("[ERROR] | [Payroll Generation] On Line: " . __LINE__, $e);
        }

        return $payroll;
    }

    /**
     * Get Payroll Configuration
     *
     * @param array $departments
     * @return null
     * @throws \Exception
     */
    public function getPayrollConfiguration($branches)
    {
        $params = $this->params;
        $this->db->where('branch_id', $branches, 'IN');
        $queryResult = $this->db->get('config_payroll'); // change into config_payroll

        foreach ($queryResult as $configuration) {
            $configuration['late_rules'] = json_decode($configuration['late_rules']);
            $configuration['undertime_rules'] = json_decode($configuration['undertime_rules']);
            // $configuration['overbreak_rules'] = json_decode($configuration['overbreak_rules']);
            $this->configuration['payroll_config'][$configuration['branch_id']] = $configuration;
        }
    }

    /**
     * Get Custom Payroll Configuration
     *
     * @param $branches
     * @return null
     * @throws \Exception
     */
    public function getCustomPayrollConfiguration($branches)
    {
        $params = $this->params;

        $this->db->where('branch_id', $branches, 'IN');
        $this->db->orderBy('payroll_configuration_id', 'desc');
        $queryResult = $this->db->get('config_payroll', 1);

        foreach ($queryResult as $key => $configuration) {
            if ($params['late_rules'] !== "[]" && $params['late_rules']) {
                $configuration['late_rules'] = json_decode($params['late_rules']);
                foreach ($configuration['late_rules'] as $newKey) {
                    $configuration['late_rules'] = $newKey;
                }
            } else {
                $configuration['late_rules'] = json_decode($configuration['late_rules']);
            }

            if ($params['undertime_rules'] !== "[]") {
                $configuration['undertime_rules'] = json_decode($params['undertime_rules']);
                foreach ($configuration['undertime_rules'] as $newKey) {
                    $configuration['undertime_rules'] = $newKey;
                }
            } else {
                $configuration['undertime_rules'] = json_decode($configuration['undertime_rules']);
            }

            if ($params['overbreak_rules'] !== "[]") {
                $configuration['overbreak_rules'] = json_decode($params['overbreak_rules']);
                foreach ($configuration['overbreak_rules'] as $newKey) {
                    $configuration['overbreak_rules'] = $newKey;
                }
            } else {
                $configuration['overbreak_rules'] = json_decode($configuration['overbreak_rules']);
            }



            $this->configuration['payroll_config'][$configuration['branch_id']] = $configuration;
        }

        // return $configuration;
    }

    /**
     * Get Payroll Benefits
     *
     * @param array $list
     * @param int $basic_salary
     * @param array $employee_payroll_benefits
     * @return array
     * @throws \Exception
     */
    public function getPayrollBenefits($list, $start_date, $end_date, $term, $payroll_schedule, $payroll)
    {
        $benefits = array();

        $this->db->where("employee_id", $list, 'IN');
        $this->db->where('is_active', 1);
        $this->db->where(
            "start_date <= REPLACE('$end_date', '/', '-') AND
            (end_date IS NULL OR end_date = 0000-00-00 OR end_date >= REPLACE('$end_date', '/' ,'-'))"
        );
        $queryResult = $this->db->get('emp_benefits');

        if ($this->db->count > 0) {
            foreach ($queryResult as $employee_benefits) {
                $deduction_schedule = array_reverse(explode(',', $employee_benefits['deduction_schedule']));
                if ($deduction_schedule[$term - 1] == 1) {
                    if (isset($payroll[$employee_benefits['employee_id']])) {
                        $employee_id = $employee_benefits['employee_id'];
                        $benefit_id = $employee_benefits['benefit_id'];

                        $total_earnings = $payroll[$employee_id]['total_earnings'];
                        $total_statutory_deductions = $payroll[$employee_id]['total_statutory_deductions'];
                        $total_deductions = $payroll[$employee_id]['total_deductions'];

                        //for other contributions
                        $net_salary = $total_earnings - ($total_statutory_deductions + $total_deductions);

                        $benefit_table_options = [
                            1 => [
                                'reference' => 'reference_sss',
                                'config' => 'config_sss',
                            ],
                            2 => [
                                'reference' => 'reference_philhealth',
                                'config' => 'config_philhealth',
                            ],
                            3 => [
                                'reference' => 'reference_pagibig',
                                'config' => 'config_pagibig',
                            ],
                            4 => [
                                'reference' => ['reference_gsis_regular', 'reference_gsis_special'],
                                'config' => 'config_gsis',
                            ],
                        ];

                        $monthly_salary = $employee_benefits['monthly_basis'];
                        if ($employee_benefits['payroll_auto_compute'] === 1) {
                            $rate_type = $this->employee_service_records[$employee_id][0]['rate_type'];
                            $monthly_salary = $this->employee_service_records[$employee_id][0]['salary'];
                            if ($rate_type !== 0) {
                                // DAILY OR HOURLY RATER
                                $days_in_month = cal_days_in_month(CAL_GREGORIAN, $this->params['month'], $this->params['year']);
                                $work_day_keys = array_column($this->schedules[$employee_id], 'day');

                                $work_days = 0;
                                for ($day = 1; $day <= $days_in_month; $day++) {
                                    $date = new DateTime($this->params['year'] . '-' . $this->params['month'] . "-$day");
                                    if (in_array($date->format('N'), $work_day_keys)) {
                                        $work_days++;
                                    }
                                }

                                $monthly_salary = $this->employee_service_records[$employee_id][0]['salary'] * ($rate_type === 1 ? $work_days : ($work_days * 8));
                            }
                        }

                        $type = $employee_benefits['type'];

                        $reference_table = is_array($benefit_table_options[$benefit_id]['reference']) ? $benefit_table_options[$benefit_id]['reference'][$type] : $benefit_table_options[$benefit_id]['reference'];

                        $benefit_result = $this->db->rawQuery("SELECT * FROM $reference_table WHERE $monthly_salary BETWEEN monthly_salary_start AND monthly_salary_end LIMIT 1;");

                        $is_earning_below_minimum_percentage = null;

                        // check if has benefits
                        if ($benefit_result) {
                            $this->db->where('configuration_id', $benefit_result[0]['configuration_id']);
                            $this->db->where('as_of_date', $end_date, '<=');
                            $this->db->orderBy('as_of_date', 'DESC');
                            $benefit_config = $this->db->getOne($benefit_table_options[$benefit_id]['config']);
                            
                            // check if has config on benefits
                            if ($benefit_config) {
                                // get the basic salary from payroll
                                $basic_salary = 0;
                                // $adjustments = [];
                                if ($payroll) {
                                    foreach ($payroll as $basics) {
                                        $basic_salary = $basics['daily']['total_basic_salary'];
                                        // $adjustments = $basics['adjustments']
                                    }
                                }
                                $is_earning_below_minimum_percentage = $net_salary < ($basic_salary * ($benefit_config['minimum_percentage_earnings'] / 100));
                            }

                        }

                        if ($is_earning_below_minimum_percentage) {
                            // Check if earnings are below the minimum percentage threshold
                            $min_percentage_earnings_option = $benefit_config['minimum_percentage_earnings_option'];
                            // Retrieve the minimum percentage earnings option from the benefit configuration

                            // $min_percentage_earnings_option === 1 NO DEDUCTION
                            // If the option is 1, no deduction will be made

                            // Get the configured adjustments ID where the name is 'Cash Advance' and type is 0 (deduction)
                            $this->db->where('name', 'Cash Advance');
                            $this->db->where('type', 0);
                            $adjustment_deduc_id = $this->db->get('config_adjustment', null, 'adjustment_id');
                            // Retrieve the adjustment ID for Cash Advance deductions

                            $currentDate = (new DateTime())->format('Y-m-d');
                            // Get the current date in 'Y-m-d' format

                            $new_adjustment_id = 0;
                            // Initialize a variable to store the new adjustment ID

                            // Get the maximum ID from the tbl_emp_adjustments table to generate a new adjustment reference ID
                            $emp_adjustment_ids = $this->db->query('SELECT MAX(id) AS last_id FROM tbl_emp_adjustments');

                            // Get the schedule deduction by its term
                            $indices = array_keys($deduction_schedule, '1'); // Get all the indices that have '1'
                            $mapped_indices = array_map(function ($index) {
                                return $index + 1;
                            }, $indices);
                            // Map the indices to their corresponding terms by adding 1 to each index

                            $key = array_search($term, $mapped_indices);
                            // Search for the current term in the mapped indices

                            if ($key !== false) {
                                // If $terms is found, start from $terms and get the next value
                                $next_key = ($key + 1) % count($mapped_indices); // Circular behavior
                                $term = $mapped_indices[$next_key];
                            } else {
                                // If $terms is not found, default to the first value
                                $term = $mapped_indices[0];
                            }
                            // If the term is found and the next term exists, update the term to the next one

                            if ($min_percentage_earnings_option === 2) {
                                // If the minimum percentage earnings option is 2, add Cash Advance pay later: current deduction
                                // Check if emp_adjustment_ids has a value
                                if ($emp_adjustment_ids) {
                                    // If emp_adjustment_ids has a value, increment the last ID to generate a new adjustment ID
                                    $new_adjustment_id = $emp_adjustment_ids[0]['last_id'] + 1;
                                }
                                // Prepare the adjustment details for insertion
                                $adjusment_details = [
                                    'employee_id' => $employee_id,
                                    'adjustment_id' => $adjustment_deduc_id[0]['adjustment_id'],
                                    'emp_adjustment_ref_id' => 'JHR-ADJ-'.$new_adjustment_id,
                                    'month_year' => $currentDate,
                                    'term' => $term,
                                    'amount' => $benefit_result[0]['ss_ee'] / count($indices),
                                    'remarks' => 'ADD CA PAY LATER: CURRENT DEDUCTION',
                                    'payroll_posted' => null,
                                ];
                                
                                // Before inserting, verify if the data already exists in the emp_adjustments table
                                $this->db->where('employee_id', $adjusment_details['employee_id']);
                                $this->db->where('adjustment_id', $adjusment_details['adjustment_id']);
                                $this->db->where('month_year', $adjusment_details['month_year']);
                                $this->db->where('term', $adjusment_details['term']);
                                $tbl_adjustments = $this->db->get('emp_adjustments');
                                if (!$tbl_adjustments) {
                                    // If the data does not exist, insert the new adjustment details
                                    $this->db->insert('emp_adjustments', $adjusment_details);
                                }
                                // Get the value of current benefits and push it to the adjustments table
                            } elseif ($min_percentage_earnings_option === 3) {
                                // If the minimum percentage earnings option is 3, add Cash Advance pay later: minimum deduction
                                // Get the minimum value from the brackets and push it to the adjustments table
                                // Get the configured adjustments ID where the name is 'Cash Advance' and type is deduction
                                if ($emp_adjustment_ids) {
                                    // If emp_adjustment_ids has a value, increment the last ID to generate a new adjustment ID
                                    $new_adjustment_id = $emp_adjustment_ids[0]['last_id'] + 1;
                                }
                                // Retrieve the minimum deduction amount from the reference table
                                if ($reference_table === 'reference_sss') {
                                    $minimun_deduction = $this->db->rawQuery("SELECT MIN(ss_ee) as amount FROM $reference_table WHERE 0 BETWEEN monthly_salary_start AND monthly_salary_end LIMIT 1;");
                                    if (!empty($minimun_deduction) && isset($minimun_deduction[0])) {
                                        $minimun_deduction[0]['amount'] = $minimun_deduction[0]['amount'] / count($indices);
                                    }
                                } elseif ($reference_table === 'reference_philhealth' || $reference_table === 'reference_pagibig') {
                                    $minimun_deduction = $this->db->rawQuery("SELECT MIN(ee_amount) as amount FROM $reference_table WHERE 0 BETWEEN monthly_salary_start AND monthly_salary_end LIMIT 1;");
                                    if (!empty($minimun_deduction) && isset($minimun_deduction[0])) {
                                        $minimun_deduction[0]['amount'] = $minimun_deduction[0]['amount'] / count($indices);
                                    }
                                }
                               
                                // Prepare the adjustment details for insertion
                                $adjustment_details = [
                                    'employee_id' => $employee_id,
                                    'adjustment_id' => $adjustment_deduc_id[0]['adjustment_id'],
                                    'emp_adjustment_ref_id' => 'JHR-ADJ-'.$new_adjustment_id,
                                    'month_year' => $currentDate,
                                    'term' => $term,
                                    'amount' => $minimun_deduction[0]['amount'],
                                    'remarks' => 'ADD CA PAY LATER: MINIMUM DEDUCTION',
                                    'payroll_posted' => null,
                                ];

                                // Before inserting, verify if the data already exists in the emp_adjustments table
                                $this->db->where('employee_id', $adjustment_details['employee_id']);
                                $this->db->where('adjustment_id', $adjustment_details['adjustment_id']);
                                $this->db->where('month_year', $adjustment_details['month_year']);
                                $this->db->where('amount', $adjustment_details['amount']);
                                $tbl_adjustments = $this->db->get('emp_adjustments');
                                if (!$tbl_adjustments) {
                                    // If the data does not exist, insert the new adjustment details
                                    $this->db->insert('emp_adjustments', $adjustment_details);
                                }
                            }
                        } else {
                            // If earnings are not below the minimum percentage threshold
                            if ($employee_benefits['benefit_id'] == 5) { // Check if the benefit is TAX
                                $payroll[$employee_id]['tax'] = $employee_benefits['e_share'];
                                // Set the tax amount for the employee
                            } else {
                                // If the benefit is not TAX
                                if (!isset($payroll[$employee_id]['benefits']['benefits'][$employee_benefits['benefit_id']])) {
                                    // Initialize the benefits array for the employee if it doesn't exist
                                    $payroll[$employee_id]['benefits']['benefits'][$benefit_id] = array();
                                    $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['benefit_id'] = $benefit_id;
                                    $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['payroll_id'] = $payroll[$employee_id]['payroll_id'];
                                }
                                /*
                                * COUNT TERMS
                                */
                                $terms = substr_count($employee_benefits['deduction_schedule'], '1');
                                // Count the number of terms based on the deduction schedule

                                if (isset($employee_benefits['payroll_auto_compute']) && $employee_benefits['payroll_auto_compute'] == 0) {
                                    // If payroll auto compute is disabled
                                    $ec_share = $employee_benefits['ec_share'] ?? 0;
                                    // Retrieve the employer contribution share, default to 0 if not set

                                    /*
                                    * DIVIDE EMPLOYEE CONTRIBUTION BASED ON NUMBER OF TERMS
                                    */
                                    $employee_benefits['e_share'] = $employee_benefits['e_share'] / $terms;
                                    $employee_benefits['pf_e'] = $employee_benefits['pf_e'] ?? 0;
                                    $employee_benefits['pf_e'] / $terms;
                                    // Divide the provident fund employee contribution by the number of terms

                                    // Not auto compute
                                    $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['basic_salary'] = $employee_benefits['monthly_basis'];
                                    $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['amount'] = $employee_benefits['e_share'];
                                    $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['er_share'] = $employee_benefits['er_share'];
                                    $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['ec_share'] = $ec_share;
                                    $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['pf_e'] = $employee_benefits['pf_e'];
                                    $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['pf_er'] = $employee_benefits['pf_er'] ?? 0;

                                } else { // ? PAYROLL AUTO COMPUTE
                                    if ($benefit_result) {
                                        if ($benefit_id == 1) { // Check if the benefit is SSS
                                            $ec_share = $benefit_result['ec_er'] ?? 0;
                                            // Retrieve the employer contribution share for SSS, default to 0 if not set
                                            $amount = $benefit_result['ss_ee'] / $terms;
                                            // Calculate the employee contribution amount per term

                                            $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['basic_salary'] = $benefit_result['salary_base'];
                                            $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['amount'] = $amount;
                                            $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['er_share'] = $benefit_result['ss_er'];
                                            $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['ec_share'] = $ec_share;
                                            $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['pf_e'] = $benefit_result['pf_e'] ?? 0;
                                            $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['pf_er'] = $benefit_result['pf_er'] ?? 0;
                                            // Set the SSS benefit details for the employee
                                        } else {
                                            // For other benefits
                                            $amount = $monthly_salary * ($benefit_result['ee_percentage'] / 100);
                                            // Calculate the employee contribution amount based on the percentage
                                            if ($benefit_result['method'] == 2) {
                                                $amount = $benefit_result['ee_amount'];
                                                // If the method is 2, use the fixed employee contribution amount
                                            }
                                            $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['amount'] = $amount / $terms;
                                            // Set the benefit amount per term
                                        }
                                    }
                                }

                                array_push($benefits, $payroll[$employee_id]['benefits']['benefits'][$benefit_id]);
                                // Add the benefit details to the benefits array
                                $benefit_amount = $payroll[$employee_id]['benefits']['benefits'][$benefit_id]['amount'];
                                // Retrieve the benefit amount
                                $payroll[$employee_id]['benefits']['total_amount'] += $benefit_amount;
                                // Add the benefit amount to the total benefits amount
                                $payroll[$employee_id]['total_statutory_deductions'] += $benefit_amount;
                                // Add the benefit amount to the total statutory deductions
                            }
                        }
                    }
                }
            }
        }

        // * INSERT BENEFITS
        $this->db->insertMulti('pr_benefits', $benefits);

        /*
         * Logger
         */
        if ($this->db->getLastErrno() !== 0) {
            $this->Logger->LogError("[ERROR] | InsertMulti [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
        }

        return $payroll;
    }

    /**
     * Fetch Employee Loans
     *
     * @param array $list
     * @param string $end_date
     * @param string $term
     * @return array
     * @throws \Exception
     */
    public function getPayrollLoans($list, $end_date, $term, $payroll)
    {
        $this->db->orderBy("config.priority", "asc");
        $this->db->orderBy("loan.employee_id", "asc");
        $this->db->where('config.status', 1);
        $this->db->where("loan.employee_id", $list, 'IN');
        $this->db->where("(FIND_IN_SET('" . $term . "',loan.deduction_schedule))");
        $this->db->where("(loan.start_date <= '" . $end_date . "' AND (loan.end_date IS NULL OR loan.end_date >= '" . $end_date . "')) ");
        $this->db->join('config_loans as config', 'config.loan_id = loan.loan_id', 'LEFT');
        $cols = "config.*,
                loan.*,
                (length(loan.deduction_schedule) - length(replace(loan.deduction_schedule, ',', '')) + 1) AS no_of_terms,
                (
                    SELECT SUM(pl.amount)
                    FROM tbl_pr_loan pl
                    LEFT JOIN tbl_pr_information pi
                    ON pl.payroll_id=pi.payroll_id
                    WHERE pl.loan_record_id=loan.id
                    AND pi.is_deleted=0
                ) AS deducted";
        $queryResult = $this->db->get("emp_loans as loan", null, $cols);

        $loans = array();
        if ($this->db->count > 0) {
            foreach ($queryResult as $employee_loans) {
                if (isset($payroll[$employee_loans['employee_id']])) {
                    $employee_loans['balance'] = $employee_loans['loan_amount'] - $employee_loans['deducted'];
                    $total_take_home_pay = $payroll[$employee_loans['employee_id']]['total_earnings'] - ($payroll[$employee_loans['employee_id']]['total_statutory_deductions'] + $payroll[$employee_loans['employee_id']]['total_deductions']);
                    // Loan can only be deducted if salary is enough for that.
                    if ($total_take_home_pay > $employee_loans['amortization'] && $employee_loans['balance'] > 0) {
                        $payroll[$employee_loans['employee_id']]['loans']['total_amount'] += $employee_loans['amortization'];
                        $emp_loan = array('payroll_id' => $payroll[$employee_loans['employee_id']]['payroll_id'], 'loan_id' => $employee_loans['loan_id'], 'loan_record_id' => $employee_loans['id'], 'amount' => $employee_loans['amortization']);
                        array_push($payroll[$employee_loans['employee_id']]['loans']['loans'], $emp_loan);
                        $payroll[$employee_loans['employee_id']]['total_deductions'] += $employee_loans['amortization'];
                        array_push($loans, $emp_loan);
                    }
                }
            }
        }
        //insert loans
        $this->db->insertMulti('pr_loan', $loans);

        if ($this->db->getLastErrno() !== 0) {
            $this->Logger->LogError("[ERROR] | InsertMulti [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
        }

        return $payroll;
    }

    /**
     * Fetch Employee Allowances
     *
     * @param array $list
     * @param string $start_date
     * @param string $end_date
     * @return array
     * @throws \Exception
     */
    public function getPayrollAllowance($list, $start_date, $end_date, $term, $payroll)
    {
        $allowances = array();
        $this->db->where("allowance.employee_id", $list, 'IN');
        $this->db->where("config.include_to_payroll", '1');
        $this->db->where(" (FIND_IN_SET('" . $term . "',allowance.payroll_schedule )) ");
        $this->db->join('config_allowances as config', 'config.allowance_id = allowance.allowance_id', 'LEFT');
        $this->db->where("(allowance.end_date >= ? OR allowance.end_date IS NULL) AND allowance.start_date <= ? ", array($start_date, $end_date));

        // $this->db->orderBy("allowance.employee_id", "asc");
        // $this->db->orderBy("config.include_to_payroll", "asc");

        $queryResult = $this->db->get("emp_allowances as allowance", null, "config.*,allowance.*,(length(allowance.payroll_schedule) - length(replace(allowance.payroll_schedule, ',', '')) + 1) AS no_of_terms");

        if ($this->db->count > 0) {
            foreach ($queryResult as $employee_allowances) {
                if (isset($payroll[$employee_allowances['employee_id']])) {
                    $total_allowance = 0;

                    if (!isset($payroll[$employee_allowances['employee_id']]['allowance'][$employee_allowances['id']])) {
                        $payroll[$employee_allowances['employee_id']]['allowance']['allowances'][$employee_allowances['id']] = array();
                        $payroll[$employee_allowances['employee_id']]['allowance']['allowances'][$employee_allowances['id']]['allowance_id'] = $employee_allowances['allowance_id'];
                        $payroll[$employee_allowances['employee_id']]['allowance']['allowances'][$employee_allowances['id']]['allowance_record_id'] = $employee_allowances['id'];
                        $payroll[$employee_allowances['employee_id']]['allowance']['allowances'][$employee_allowances['id']]['term'] = $employee_allowances['term'];
                        $payroll[$employee_allowances['employee_id']]['allowance']['allowances'][$employee_allowances['id']]['payroll_id'] = $payroll[$employee_allowances['employee_id']]['payroll_id'];
                    }

                    switch ($employee_allowances['term']) {
                        case '0':
                            /**
                             * OLD CODE
                             */
                            //for daily allowance
                            // $end_dates_to_deduct = 0;
                            // $start_dates_to_deduct = DateTimeHelper::dateHourDiff($start_date, $employee_allowances['start_date']) / 24;
                            // if ($employee_allowances['end_date'] != null) {
                            //     $end_dates_to_deduct = DateTimeHelper::dateHourDiff($employee_allowances['end_date'], $end_date) / 24;
                            // }
                            // foreach ($payroll[$employee_allowances['employee_id']]['daily']['working_hours'] as $working_info) {

                            //     //Set true/false if end_date has value
                            //     $no_end_date = is_null($employee_allowances['end_date']) ? true : false;

                            //     if ($end_dates_to_deduct > 0) {
                            //         //unset-exclude-remove end date from working hours included dates
                            //         $working_dates = $working_info['dates'];
                            //         unset($working_dates[$employee_allowances['end_date']]);
                            //         $dates = DateTimeHelper::getDatesFromRange($employee_allowances['end_date'], $end_date);
                            //         $excluded_dates = array_intersect($dates, $working_dates);
                            //         if (COUNT($excluded_dates) > 0) {
                            //             $working_info['total_paid_days'] -= COUNT($excluded_dates);
                            //         }
                            //     }
                            //     else if ($start_dates_to_deduct > 0 || $no_end_date) {
                            //         /**
                            //          * Old Code
                            //          */
                            //         //unset-exclude-remove start date from working hours included dates
                            //         // $working_dates = $working_info['dates'];
                            //         // unset($working_dates[$employee_allowances['start_date']]);
                            //         // $dates = DateTimeHelper::getDatesFromRange($start_date, $employee_allowances['start_date']);
                            //         // $excluded_dates = array_intersect($dates, $working_dates);
                            //         // if (COUNT($excluded_dates) > 0) {
                            //         //     $working_info['total_paid_days'] -= COUNT($excluded_dates);
                            //         // }

                            //         /**
                            //          * Convert Into Date.
                            //          */
                            //         $emp_allowance_start_date = new DateTime($employee_allowances['start_date']);
                            //         $emp_payroll_start_date = new DateTime($start_date);
                            //         $emp_payroll_end_date = new DateTime($end_date);

                            //         /**
                            //          * Use employee allowance start date if its greater or equal than payroll start date else
                            //          * Use payroll start date
                            //          */
                            //         $new_start_date = $emp_allowance_start_date >= $emp_payroll_start_date ? $emp_allowance_start_date : $emp_payroll_start_date;

                            //         /**
                            //          * Deduct one day to satisfy correct number of days
                            //          */
                            //         $new_start_date->modify('-1 day');

                            //         /**
                            //          * Calculate How Many Working Days An Employee Should Be Given An Allowance.
                            //          */
                            //         $interval = $new_start_date->diff($emp_payroll_end_date);
                            //         $working_days = $interval->days;

                            //         /**
                            //          * Compute Total Employee Allowance
                            //          * total_allowance = total_allowance + $daily * working_days
                            //          */
                            //         $daily = $employee_allowances['amount'];
                            //         $total_allowance += $daily * $working_days;

                            //     }


                            // }


                            /**
                             * NEW CODE
                             */

                            /** Daily Allowance */
                            foreach ($payroll[$employee_allowances['employee_id']]['daily']['working_hours'] as $working_info) {

                                /** Determine if end_date was set on employee allowance */
                                $no_end_date = is_null($employee_allowances['end_date']) ? true : false;

                                /** Convert Into Date. */
                                $emp_allowance_start_date = new DateTime($employee_allowances['start_date']);
                                $emp_payroll_start_date = new DateTime($start_date);
                                $emp_payroll_end_date = new DateTime($end_date);

                                if ($no_end_date) {

                                    /** Decide Which Start Date Must Use */
                                    $new_start_date = $emp_allowance_start_date >= $emp_payroll_start_date ? $emp_allowance_start_date : $emp_payroll_start_date;
                                    /** Compute Total Allowance */
                                    $total_allowance += $this->computeTotalAllowance($new_start_date, $emp_payroll_end_date, $employee_allowances);
                                } else {

                                    $emp_allowance_end_date = new DateTime($employee_allowances['end_date']);

                                    /** Employee allowance end date must within the payroll date generated */
                                    if ($emp_payroll_start_date <= $emp_allowance_end_date) {

                                        /** Check if employee allowance start date is within payroll date generated */
                                        if ($emp_allowance_start_date >= $emp_payroll_start_date) {
                                            $new_start_date = $emp_allowance_start_date;
                                        } else {
                                            $new_start_date = $emp_payroll_start_date;
                                        }

                                        /** Compute Total Allowance */
                                        $total_allowance += $this->computeTotalAllowance($new_start_date, $emp_allowance_end_date, $employee_allowances);
                                    }
                                }
                            }
                            break;
                        case '1':
                        case '2':
                            //for Monthly allowance
                            //for Fixed allowance
                            $total_allowance += $employee_allowances['amount'];
                            break;
                    }
                    $payroll[$employee_allowances['employee_id']]['allowance']['allowances'][$employee_allowances['id']]['amount'] = $total_allowance;
                    $payroll[$employee_allowances['employee_id']]['allowance']['total_amount'] += $total_allowance;
                    $payroll[$employee_allowances['employee_id']]['total_earnings'] += $total_allowance;
                    array_push($allowances, $payroll[$employee_allowances['employee_id']]['allowance']['allowances'][$employee_allowances['id']]);
                }
            }
        }
        //insert Allowance
        $this->db->insertMulti('pr_allowance', $allowances);

        /**
         * Logger
         */
        if ($this->db->getLastErrno() !== 0) {
            $this->Logger->LogError("[ERROR] | InsertMulti [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
        }

        return $payroll;
    }

    /**
     * Compute Total Allowance
     *
     * @param dateTime $new_start_date
     * @param dateTime $emp_payroll_end_date
     * @param int $employee_allowances
     * @return int
     * @throws \Exception
     */
    public function computeTotalAllowance($new_start_date, $emp_payroll_end_date, $employee_allowances)
    {

        /** Deduct one day to satisfy correct number of days */
        $new_start_date->modify('-1 day');

        /** Calculate How Many Working Days An Employee Should Be Given An Allowance. */
        $interval = $new_start_date->diff($emp_payroll_end_date);
        $working_days = $interval->days;

        /** total_allowance = total_allowance + $daily * working_days */
        $daily = $employee_allowances['amount'];
        return $daily * $working_days;
    }

    /**
     * Fetch Employee Leaves
     *
     * @param array $list
     * @param string $start_date
     * @param string $end_date
     * @return array
     * @throws \Exception
     */
    public function getLeaves($list, $start_date, $end_date)
    {
        $payroll_start_date  = strtotime($start_date);
        $payroll_end_date  = strtotime($end_date);
        $leaves = array();
        $this->db->where("employee_id", $list, 'IN');
        $this->db->where('is_posted', '2', '<=');
        $this->db->where('deleted_at', '0', '=');
        $this->db->where('leave_status', '2');

        /** COmment ko muna tong part ng code kasi nag cacause ng 500 Internal server errror and wala logs
         * -milz 021525
         */
        // $this->db->where("DATE(end_date)", $end_datetime->format("Y-m-d"), '<=');
        // $this->db->where("DATE(start_date)", $start_datetime->format("Y-m-d"), '>=');
        // $this->db->orWhere("DATE(start_date)", $end_datetime->format("Y-m-d"), '<=');
        // $this->db->join('leave_types as ltype', 'ltype.leave_type_id = l.leave_type_id', 'LEFT');
        // $cols = "l.*,
        //         (SELECT SUM(pl.credits)
        //             FROM tbl_pr_leave as pl
        //             WHERE pl.leave_record_id=l.id) AS posted_credits";
        $queryResult = $this->db->get('emp_leaves');

        if ($this->db->count > 0) {
            foreach ($queryResult as &$employee_leaves) {
                $leave_start_date = strtotime($employee_leaves['start_date']);
                $leave_end_date = strtotime($employee_leaves['end_date']);

                // Check if either leave start date or leave end date is within the payroll period
                if (($leave_start_date >= $payroll_start_date && $leave_start_date <= $payroll_end_date) || ($leave_end_date >= $payroll_start_date && $leave_end_date <= $payroll_end_date)) {
                    $this->db->where('leave_record_id', $employee_leaves["id"]);
                    $posted_credits = $this->db->getOne('pr_leave', "sum(credits) as posted_credits");
                    if (!isset($leaves[$employee_leaves['employee_id']])) {
                        $leaves[$employee_leaves['employee_id']][$employee_leaves['id']] = array();
                    }
                    $employee_leaves['balance'] = isset($posted_credits["posted_credits"]) ? $posted_credits["posted_credits"] : 0;
                    $leaves[$employee_leaves['employee_id']][$employee_leaves['id']] = $employee_leaves;
                }
            }
        } else {
            $leaves = array();
        }
        return $leaves;
    }

    /**
     * @param array $leaves
     * @param $employee_id
     * @param $start_date
     * @param $end_date
     * @return void
     * @throws \Exception
     */
    public function getPayrollLeaves($employee_id, $start_date, $end_date, $payroll_id)
    {

        $pr_leaves = array();
        $posted_ids = array();
        $partial_ids = array();

        $queryResult = $this->getLeaves([$employee_id], $start_date, $end_date);

        if (count($queryResult)) {
            // Get Payroll Date Information
            $this->db->where('date', $start_date, '>=');
            $this->db->where('date', $end_date, '<=');
            $this->db->where('payroll_id', $payroll_id, '=');
            $check = $this->db->get('pr_date_information', null);

            foreach ($queryResult[$employee_id] as $payroll_leaves) {
                if ($payroll_leaves["is_paid"] === 1 && $payroll_leaves["is_posted"] >= 0 && $payroll_leaves['credits_used'] >= 0.5) {
                    $payroll_start =  $payroll_leaves["start_date"];
                    $payroll_end = $payroll_leaves["end_date"];
                    $begin = new DateTime($payroll_start);
                    $end = new DateTime($payroll_end);
                    $pr_dates = array();

                    if ($this->db->count > 0) {
                        foreach ($check as $pr_date_information) {
                            $pr_dates[$pr_date_information["date"]] = $pr_date_information;
                        }
                    }

                    // compute credits Used
                    // check if leaves not fully catered in cut off
                    $credits_used = 0;
                    if (!isset($pr_dates[$payroll_leaves["end_date"]]) || !isset($pr_dates[$payroll_leaves["start_date"]])) {
                        $begin_compute_credits = !isset($pr_dates[$payroll_leaves["end_date"]]) ? new DateTime($payroll_leaves["start_date"]) : new DateTime($start_date);
                        $end_compute_credits = !isset($pr_dates[$payroll_leaves["start_date"]]) ? new DateTime($payroll_leaves["end_date"]) : new DateTime($end_date);

                        for ($i = $begin_compute_credits; $i <= $end_compute_credits; $i->modify('+1 day')) {
                            $date = $i->format('Y-m-d');

                            if ($payroll_leaves["start_date"] =  $date) {
                                // Whole day leave
                                if ($payroll_leaves['start_period'] == "0" && $payroll_leaves['end_period'] == "0") {
                                    $credits_used += 1;
                                } else {
                                    $credit_working_hours = $pr_dates[$date]["first_period_hours"] / $pr_dates[$date]['total_working_hours'];
                                    $credits_used += number_format($credit_working_hours, 4, '.', '');
                                }
                            } else {
                                $credits_used += 1;
                            }
                        }

                        if (!isset($pr_dates[$payroll_leaves["end_date"]])) {
                            array_push($partial_ids, $payroll_leaves['id']);
                        } else {
                            array_push($posted_ids, $payroll_leaves['id']);
                        }
                    } else {
                        array_push($posted_ids, $payroll_leaves['id']);
                        $credits_used = (float)$payroll_leaves["credits_used"];
                    }


                    for ($i = $begin; $i <= $end; $i->modify('+1 day')) {
                        $date = $i->format('Y-m-d');
                        if (!isset($pr_dates[$date])) {
                            continue;
                        }
                        $total_working_hours = $pr_dates[$date]["total_working_hours"];
                        $emp_sched = json_decode($pr_dates[$date]["schedule"], true);
                        $first_periods = $emp_sched["logins"]["first_period_working_hours"];
                        $second_periods = $emp_sched["logins"]["second_period_working_hours"];

                        $hourly_rate = $pr_dates[$date]['hourly_rate'];
                        $first_period_hours = $pr_dates[$date]['first_period_hours'];
                        $second_period_hours = $pr_dates[$date]['second_period_hours'];
                        $first_period_amount = $first_period_hours * $hourly_rate;
                        $second_period_amount = $second_period_hours * $hourly_rate;

                        $leave_period = 0;
                        $test_leave = array();

                        // Check if Start Period is equal to whole day and Start and End Date are equal
                        if (
                            $payroll_leaves["start_period"] == 0 &&
                            $payroll_leaves["start_date"] === $payroll_leaves["end_date"]
                        ) {
                            $leave_period = 3;
                            $total_amount = $first_period_amount + $second_period_amount;
                        }

                        // Check if Start Period is equal to 0 and Start Date is less than End Date
                        if (
                            $payroll_leaves["start_period"] == 0 &&
                            $payroll_leaves["start_date"] < $payroll_leaves["end_date"]
                        ) {
                            $leave_period = 3;
                            // Check if the Date iterated is equal to the Start Date
                            if ($date == $payroll_leaves['start_date']) {
                                $first_period_amount = $pr_dates[$date]['first_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                $second_period_amount = $pr_dates[$date]['second_period_hours'] * $pr_dates[$date]['hourly_rate'];
                            }
                            // Check if the Date iterated is not equal to both Start and End Date
                            if ($date != $payroll_leaves['start_date'] && $date != $payroll_leaves['end_date']) {
                                $first_period_amount = $pr_dates[$date]['first_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                $second_period_amount = $pr_dates[$date]['second_period_hours'] * $pr_dates[$date]['hourly_rate'];
                            }
                            // Check if the Date Iterated is equal to End Date and End Period equal to 1
                            if ($date == $payroll_leaves['end_date'] && $payroll_leaves["end_period"] == 1) {
                                $first_period_amount = $pr_dates[$date]['first_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                $second_period_amount = 0;
                            }
                            // Check if the Date Iterated is equal to End Date and End Period equal to 0
                            if ($date == $payroll_leaves['end_date'] && $payroll_leaves["end_period"] == 0) {
                                $first_period_amount = $pr_dates[$date]['first_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                $second_period_amount = $pr_dates[$date]['second_period_hours'] * $pr_dates[$date]['hourly_rate'];
                            }
                            $total_amount = $first_period_amount + $second_period_amount;
                        }

                        // Check if start period is half day first period
                        if (
                            $payroll_leaves["start_period"] == 1 &&
                            $payroll_leaves["end_period"] == 0
                        ) {
                            // Check if Both Start and End Date are equal
                            if ($payroll_leaves['end_date'] == $payroll_leaves['start_date']) {
                                $first_period_amount += $pr_dates[$date]['first_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                $second_period_amount += 0;
                                $leave_period = 1;
                                $total_amount = $first_period_amount + $second_period_amount;
                            }
                            // Check if Start and End Date are not equal
                            if ($payroll_leaves['end_date'] != $payroll_leaves['start_date']) {
                                if ($date == $payroll_leaves["start_date"] && $payroll_leaves["start_period"] == 1) {
                                    $first_period_amount = 0;
                                    $second_period_amount = $pr_dates[$date]['second_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                }
                                // Check if the Date iterated is not equal to both Start and End Date
                                if ($date != $payroll_leaves['start_date'] && $date != $payroll_leaves['end_date']) {
                                    $first_period_amount = $pr_dates[$date]['first_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                    $second_period_amount = $pr_dates[$date]['second_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                }
                                // Check if the Date Iterated is equal to End Date and End Period equal to 0
                                if ($date == $payroll_leaves['end_date'] && $payroll_leaves["end_period"] == 0) {
                                    $first_period_amount = $pr_dates[$date]['first_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                    $second_period_amount = $pr_dates[$date]['second_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                }
                                $leave_period = 3;
                                $total_amount = $first_period_amount + $second_period_amount;
                            }
                        }

                        // Check if Both Start and End Period is half day
                        if (
                            $payroll_leaves["start_period"] == 1 &&
                            $payroll_leaves["end_period"] == 1
                        ) {
                            // Check if Both Start and End Date are equal
                            if ($payroll_leaves['end_date'] == $payroll_leaves['start_date']) {
                                $first_period_amount += 0;
                                $second_period_amount += $pr_dates[$date]['second_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                $leave_period = 2;
                                $total_amount = $first_period_amount + $second_period_amount;
                            }
                            // Check if Start and End Date are not equal
                            if ($payroll_leaves['end_date'] != $payroll_leaves['start_date']) {
                                // Check if Start date is equal to Date Iterated and End Period equal to 1
                                if ($date == $payroll_leaves['start_date'] && $payroll_leaves["end_period"] == 1) {
                                    $first_period_amount = 0;
                                    $second_period_amount = $pr_dates[$date]['second_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                    $check_amount = $first_period_amount + $second_period_amount;
                                    array_push($test_leave, $check_amount);
                                }
                                // Check if both Start and End Date is not equal to Date iterated
                                if ($date != $payroll_leaves['start_date'] && $date != $payroll_leaves['end_date']) {
                                    $first_period_amount = $pr_dates[$date]['first_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                    $second_period_amount = $pr_dates[$date]['second_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                    $check_amount = $first_period_amount + $second_period_amount;
                                    array_push($test_leave, $check_amount);
                                }
                                // Check if End Date is equal to Date iterated and End Period is equal to 1
                                if ($date == $payroll_leaves['end_date'] && $payroll_leaves["end_period"] == 1) {
                                    $first_period_amount = $pr_dates[$date]['first_period_hours'] * $pr_dates[$date]['hourly_rate'];
                                    $second_period_amount = 0;
                                    $check_amount = $first_period_amount + $second_period_amount;
                                    array_push($test_leave, $check_amount);
                                }
                                $leave_period = 3;
                                $total_amount = $first_period_amount + $second_period_amount;
                            }
                        }

                        if (strtotime($date) <= strtotime($end_date)) {
                            if (
                                isset($pr_dates[$date]) &&
                                $pr_dates[$date]['schedule'] !== '' &&
                                !empty($pr_dates[$date]['schedule'])
                            ) {
                                array_push(
                                    $pr_leaves,
                                    array(
                                        "payroll_id" => $payroll_id,
                                        "amount" => $total_amount,
                                        "from_previous_cutoff" => "1",
                                        "credits" => $credits_used,
                                        "leave_record_id" => $payroll_leaves["id"],
                                        "start_date" => $payroll_leaves["start_date"],
                                        "end_date" => $payroll_leaves["end_date"],
                                        "remarks" => $payroll_leaves["remarks"],
                                        "start_period" => $payroll_leaves["start_period"],
                                        "end_period" => $payroll_leaves["end_period"],
                                        "first_period_hours" => $first_periods,
                                        "second_period_hours" => $second_periods,
                                        "hourly_rate" => $pr_dates[$date]["hourly_rate"],
                                        "leave_period" => $leave_period
                                    )
                                );
                            }
                        }
                    }
                } else {
                    continue;
                }
            }
        }


        $leave_result = [];

        // Check if it has Leaves
        if (count($pr_leaves) > 0) {

            $duplicate_start_date = [];

            for ($i = 0; $i < count($pr_leaves); $i++) {

                /** If leaves is within a certain range */
                if ($pr_leaves[$i]['start_date'] < $pr_leaves[$i]['end_date']) {

                    //Iterate leaves based on leave_record_id
                    foreach ($pr_leaves as $leaves_values => $values) {

                        /** OLD CODE */
                        // $id = $values['leave_record_id'];
                        // $leave_result[$id]['payroll_id'] = $values['payroll_id'];
                        // $leave_result[$id]['start_date'] = $values['start_date'];
                        // $leave_result[$id]['start_period'] = $values['start_period'];
                        // $leave_result[$id]['end_date'] = $values['end_date'];
                        // $leave_result[$id]['end_period'] = $values['end_period'];
                        // $leave_result[$id]['from_previous_cutoff'] = $values['from_previous_cutoff'];
                        // $leave_result[$id]['credits'] = $values['credits'];
                        // $leave_result[$id]['first_period_hours'] = $values['first_period_hours'];
                        // $leave_result[$id]['second_period_hours'] = $values['second_period_hours'];
                        // $leave_result[$id]['hourly_rate'] = $values['hourly_rate'];
                        // $leave_result[$id]['remarks'] = $values['remarks'];
                        // $leave_result[$id]['leave_period'] = $values['leave_period'];
                        // // Set amount into 0 if amount is not set.
                        // if (!isset($leave_result[$id]['amount'])) {
                        //     $leave_result[$id]['amount'] = 0;
                        // }
                        // // Change amount 0 into value given in each iteration and sum up amount with same index id
                        // if ($leave_result[$id]['amount'] == 0) {
                        //     $leave_result[$id]['amount'] += $values['amount'];
                        // } else {
                        //     $leave_result[$id]['amount'] += $values['amount'];
                        // }

                        /** NEW CODE */

                        /** This code is a quick fix on problem that when employee have multiple leaves, it summed up the amount into one. - Son */
                        if (!in_array($values['start_date'], $duplicate_start_date)) {

                            array_push($duplicate_start_date, $values['start_date']);
                            $id = $values['leave_record_id'];

                            /** if credits is greater than to one assign credit values, else assign 1 */
                            $credits = $values['credits'] > 1 ? $values['credits'] : 1;

                            array_push($leave_result, array(
                                'payroll_id'            => $values['payroll_id'],
                                'start_date'            => $values['start_date'],
                                'start_period'          => $values['start_period'],
                                'end_date'              => $values['end_date'],
                                'end_period'            => $values['end_period'],
                                'leave_record_id'       => $values['leave_record_id'],
                                'from_previous_cutoff'  => $values['from_previous_cutoff'],
                                'credits'               => $values['credits'],
                                'first_period_hours'    => $values['first_period_hours'],
                                'second_period_hours'   => $values['second_period_hours'],
                                'hourly_rate'           => $values['hourly_rate'],
                                'remarks'               => $values['remarks'],
                                'leave_period'          => $values['leave_period'],
                                'amount'                => $values['amount'] * $credits
                            ));
                        } else {
                            // $leave_result_length = count($leave_result) - 1;
                            // $leave_result[$leave_result_length]['amount'] += $values['amount'];
                            continue;
                        }
                    }
                }
            }

            $new_pr_leaves = array();
            foreach ($leave_result as $newKey => $newValue) {
                $new_pr_leaves[] = array(
                    'payroll_id' => $newValue['payroll_id'],
                    'amount' => $newValue['amount'],
                    'from_previous_cutoff' => $newValue['from_previous_cutoff'],
                    'credits' => $newValue['credits'],
                    'leave_record_id' => $newValue["leave_record_id"],
                    'start_date' => $newValue['start_date'],
                    'start_period' => $newValue['start_period'],
                    'end_date' => $newValue['end_date'],
                    'end_period' => $newValue['end_period'],
                    'first_period_hours' => $newValue['first_period_hours'],
                    'second_period_hours' => $newValue['second_period_hours'],
                    'hourly_rate' => $newValue['hourly_rate'],
                    'remarks' => $newValue['remarks'],
                    'leave_period' => $newValue['leave_period']
                );
            }

            if (empty($leave_result)) {
                $new_pr_leaves = $pr_leaves;
            }
        } else {
            $new_pr_leaves = $pr_leaves;
        }
        // $this->db->where('payroll_id', $payroll_id, '=');

        $this->db->insertMulti('pr_leave', $new_pr_leaves);

        /**
         * Logger
         */
        if ($this->db->getLastErrno() !== 0) {
            $this->Logger->LogError("[ERROR] | InsertMulti [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
        }

        if (isset($posted_ids) && !empty($posted_ids)) {
            $this->db->where("id", $posted_ids, 'IN');
            $this->db->update('emp_leaves', array('is_posted' => 2));

            /** Logger */
            if ($this->db->getLastErrno() !== 0) {
                $this->Logger->LogError("[ERROR] | Update [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
            }
        }

        if (isset($partial_ids) && !empty($partial_ids)) {
            $this->db->where("id", $partial_ids, 'IN');
            $this->db->update('emp_leaves', array('is_posted' => 1));

            /** Logger */
            if ($this->db->getLastErrno() !== 0) {
                $this->Logger->LogError("[ERROR] | Update [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
            }
        }



        return $pr_leaves;
    }

    /**
     * Fetch Employee Additional pay
     *
     * @param array $list
     * @param string $end_date
     * @return array
     * @throws \Exception
     */
    public function getAddittionalPay($list, $start_date, $end_date)
    {
        $additional_pay = array();
        $dtr_time_helper = new DateTimeHelper();
        $this->db->where('ot.pay_type', '0');
        //$this->db->where('ot.payroll_id', '0');
        $this->db->where("CAST(start_date_time as DATE )", $start_date, '>=');
        $this->db->where("CAST(end_date_time as DATE )", $end_date, '<=');
        $this->db->where("ot.employee_id", $list, 'IN');
        $this->db->where("ot.payroll_id", 0);
        $queryResult = $this->db->get('emp_otholiday as ot', null);

        if ($this->db->count > 0) {
            foreach ($queryResult as &$employee_additional_pay) {
                if (!isset($additional_pay[$employee_additional_pay['employee_id']])) {
                    $additional_pay[$employee_additional_pay['employee_id']]['data'] = array();
                }
                // else{
                //separate overtime from start date if end date lands on the next day
                $date_start = date_format(new DateTime($employee_additional_pay['start_date_time']), 'Y-m-d');
                $date_end = date_format(new DateTime($employee_additional_pay['end_date_time']), 'Y-m-d');

                $date_start_time = $employee_additional_pay['start_date_time'];
                $date_end_time = $employee_additional_pay['end_date_time'];

                if ($date_start != $date_end) {
                    if ($date_start_time !== null && $date_end_time !== null) {
                        $first_time_diff = $dtr_time_helper->dateHourDiff($date_start_time, $date_end_time);
                    }
                    
                    $employee_additional_pay['honored_hours'] = $first_time_diff;
                    $employee_additional_pay['end_date_time'] = $date_end_time;
                    array_push($additional_pay[$employee_additional_pay['employee_id']]['data'], $employee_additional_pay);

                    /**
                     * comment ko po muna tong line of code kasi nag cause ng bug, please check if ano ang purpose ng line of code na to
                     * (Replace this comment ng purpose ng line of code na nakacomment)
                     *  - milz 021325
                     */
                    // $second_time_diff = $dtr_time_helper->dateHourDiff($date_end . " 00:00:00", $date_end_time);
                    // $employee_additional_pay['honored_hours'] = $second_time_diff;
                    // $employee_additional_pay['end_date_time'] = $date_end_time;
                    // $employee_additional_pay['start_date_time'] = $date_end . " 00:00:00";
                    // array_push($additional_pay[$employee_additional_pay['employee_id']]['data'], $employee_additional_pay);
                } else {
                    array_push($additional_pay[$employee_additional_pay['employee_id']]['data'], $employee_additional_pay);
                }
                //}


            }
        }
        return $additional_pay;
    }

    /**
     * Rate Computation for basic salary
     *
     * @param array $emp_service
     * @param string $date
     * @param string $total_working_hours
     * @return array
     * @throws \Exception
     */
    private function rateComputationsforBasicsalary($branch_id, $basic_salary, $rate_type, $working_hours)
    {
        $basic_salary = (float) $basic_salary;
        $hourly = 0;
        switch ($rate_type) {
            case "0": // * MONTHLY
                $config = $this->configuration['payroll_config'][$branch_id];
                $payroll_schedule = $this->params['payroll_schedule'];

                $month = $this->params['month'];
                $year = $this->params['year'];

                $schdule_types = [
                    'S' => 'semi',
                    'W' => 'weekly',
                ];

                /*
                 * DEFAULT DAYS IN MONTH
                 * MONTHLY
                 */
                $divisor  = cal_days_in_month(CAL_GREGORIAN, $month, $year);

                if (in_array($payroll_schedule, ['W', 'S'])) { // * SEMI OR WEEKLY
                    $schedule_type = $schdule_types[$payroll_schedule]; // ? semi OR weekly
                    $type = $config['daily_for_monthly_' . $schedule_type . '_basic_salary_dx'];
                    if (
                        $type == 1 || // * NUMBER OF WORKING DAYS
                        ($type == 0 && $config[$schedule_type . '_auto_compute_days_in_month'] == 0)
                    ) {
                        $divisor = $config['daily_for_monthly_' . $schedule_type . '_basic_salary_dy'];
                    }
                }

                $daily_rate = (float) $basic_salary / $divisor;
                $hourly = $daily_rate / 8;
                break;

            case "1": // * DAILY
                $daily_rate = $basic_salary;
                $hourly = $daily_rate / 8;
                break;

            case "2": // * HOURLY
                $daily_rate = $basic_salary * $working_hours;
                $hourly = $daily_rate / 8;
                break;
        }
        if ($working_hours != 8) {
            $daily_rate = $hourly * $working_hours;
        }
        return $this->rateComputations($daily_rate, $working_hours, $hourly);
    }

    /**
     * Rate Computations
     *
     * @param array $daily_rate
     * @param string $working_hours
     * @param string $hourly
     * @return array
     * @throws \Exception
     */
    private function rateComputations($daily_rate, $working_hours, $hourly = 0)
    {
        if ($working_hours <= 0) {
            return array(
                "dRate" => 0,
                "hdRate" => 0,
                "hRate" => 0,
            );
        } else {
            $half_day_rate = round(($daily_rate / 2), 2);
            $hourly_rate = $daily_rate / $working_hours;
            if ($hourly != 0) {
                $hourly_rate = $hourly;
            }
            return array(
                "dRate" => $daily_rate,
                "hdRate" => $half_day_rate,
                "hRate" => $hourly_rate,
            );
        }
    }

    /**
     * Generate Payroll
     *
     * @return array
     * @throws \Exception
     */
    public function generatePayroll(): mixed
    {
        // Initialize Messages class
        $this->Messages = new Messages;

        // Bind class params to local variable (why tho)
        $params = $this->params;

        // Initialize DTR Logs Getter
        $dtr_getter = new DtrLogGetter($this);

        // Bind start_date and end_date to local variable (why tho)
        $start_date = $params['start_date'];
        $end_date = $params['end_date'];

        // Initialize end date as DateTime object
        $last_date = new DateTime($end_date);
        // Increment by 1 day
        $last_date->modify('+1 day');
        // Set as last day
        $last_day = $last_date->format('Y-m-d');

        // Initialize start date as DateTime object
        $prev_start_date = new DateTime($start_date);
        // Decrement by 1 day
        $prev_start_date->modify('-1 day');
        // Set as previous start day
        $prev_start_day = $prev_start_date->format('Y-m-d');

        /*
         * Get employee and branch assignments
         */
        // Initialize branch schedule helper
        $branch_schedule_helper = new BranchScheduleHelper($this);

        if (in_array($params['column_ref'], ['branch_id', 'department_id', 'employee_id']) && array_key_exists('payroll_id', $params)) {
            // Get the employee assignments based on payroll ID
            $this->employee_branches = $branch_schedule_helper->getBranchAsignments($params['column_ref'], $params['payroll_id'], $start_date, $end_date);
        } else {
            // Get the employee assignments based on column data
            $this->employee_branches = $branch_schedule_helper->getBranchAsignments($params['column_ref'], $params['column_data'], $start_date, $end_date);
        }

        // Check if there are branch assignment
        if (!count($this->employee_branches['employees'])) {
            $error_msg = $this->Messages->jsonFailResponse("No Branch Assignment Details for Employee");
            return json_decode($error_msg);
        }

        // Get employee schedules
        $this->schedules = $branch_schedule_helper->getEmployeeSchedules($this->employee_branches['employees'], $start_date, $last_day);

        // Check if there are schedules
        if (!count($this->schedules)) {
            $error_msg = $this->Messages->jsonFailResponse("No Schedule Details for Employee");
            return json_decode($error_msg);
        }

        // Get employee memos
        $this->memos = $branch_schedule_helper->getEmployeeMemos($this->employee_branches['departments'], $this->employee_branches['employees'], $start_date, $last_day);

        // Populate employee schedules
        $this->employee_schedule = $branch_schedule_helper->populateEmployeeSchedules(
            $this->employee_branches['employees'],
            $this->schedules,
            $start_date,
            $last_day,
            $this->employee_branches,
            $this->memos
        );

        // Get employee DTR
        $this->employee_dtr = $dtr_getter->getDtr($prev_start_day, $last_day, $this->employee_branches['employees']);

        // Get employee service records
        $this->employee_service_records = $branch_schedule_helper->getServiceRecord($this->employee_branches['employees'], $start_date, $last_day);

        // Get leaves
        $this->employee_leaves = $this->getLeaves($this->employee_branches['employees'], $start_date, $end_date);

        // Get overtime
        $this->employee_overtime = $this->getAddittionalPay($this->employee_branches['employees'], $start_date, $end_date);

        // Get payroll configuration
        $this->getPayrollConfiguration($this->employee_branches['branches']);

        // Check if there is a payroll configuration
        if (!$this->configuration) {
            $error_msg = $this->Messages->jsonFailResponse("Payroll configuration has not been set");
            return json_decode($error_msg);
        }

        // Initialize containers
        $payroll = array();
        $non_success = array();
        $status = "fail";
        $message = '';

        // Loop each employee branch record
        foreach ($this->employee_branches['branch_records'] as &$employee) {
            // Bind employee id and branches to variable (why tho)
            $employee_id = $employee['employee_id'];
            $branch_departments = $employee['branches'];

            // Check if employee id is 0 or below (why tho)
            if ($employee_id <= 0) {
                continue;
            }

            // Set required fields
            $required_field = [
                'payroll_id'
            ];

            $employee_ids_existed = [];
            // Check if required fields are present
            foreach ($required_field as $field) {
                if (array_key_exists($field, $params)) {
                    // Delete existing payroll
                    $regenerate_payroll = $this->deleteExisted($employee_id, $params);

                    // Check if deletion is successful (why is there no action in case this thing fails?)
                    if ($regenerate_payroll) {
                        // $employee_ids_existed[$employee_id] = $employee_id;
                        // Update payroll status to is_deleted
                        $this->db->where('payroll_id', $params['payroll_id']);
                        $this->db->update("pr_information", ['is_deleted' => 1]);

                        if ($this->db->getLastErrno() !== 0) {
                            $this->Logger->LogError("[ERROR] | Update [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
                        }


                        // Why is there no action here in case the update fails?
                    }
                }
            }

            // Check payroll record
            $emp_payroll = $this->checkPayrollRecord($employee_id, $params);

            // Check if there is a payroll record
            if ($emp_payroll) {
                // Add payroll to non-successful generations
                $non_success[$employee_id] = [
                    'employee_id' => $employee_id,
                    'employee' => $employee['name'],
                    'remarks' => 'Payroll Already exists'
                ];
                $message = 'Payroll Already exists';
                continue;
            }

            // Check if there is a payroll configuration for the latest primary branch
            if (!isset($this->configuration['payroll_config'][$employee['latest_primary_branch']])) {
                $error_msg = $this->Messages->jsonFailResponse("Payroll configuration has not been set");
                return json_decode($error_msg);
            }

            $payroll_config = $this->configuration['payroll_config'][$employee['latest_primary_branch']];

            if (!isset($payroll_config['payroll_type']) || empty($payroll_config['payroll_type'])) { // * 0 OR NULL (PRIVATE)
                // Next data if no service record
                if (!$this->employee_service_records) {
                    $message = 'No Service Record';
                    continue;
                }

                // Next data if no service record
                if (is_array($this->employee_service_records) && !array_key_exists($employee_id, $this->employee_service_records)) {
                    $message = 'No Service Record for :' . $employee["name"];
                    continue;
                }

                // Loop each employee service record
                foreach ($this->employee_service_records as $service_records => $values) {
                    // Loop each value in service record
                    foreach ($values as $service_data => &$data) {
                        // Check if employee_id matches service record
                        if ($data['employee_id'] == $employee_id) {
                            // Set employee position and salary in service record
                            $service_record = [
                                "position" => $data['position_id'],
                                "salary" => $data['salary'],
                                'rate_type' => $data['rate_type'],
                            ];
                        }
                    }
                }
            } else {
                if ($payroll_config['payroll_type'] === 1) { // * GOVERNMENT
                    $plantilla_record = $branch_schedule_helper->getPlantillaRecord($employee_id, $start_date);
                    if (empty($plantilla_record)) {
                        $message = 'No plantilla record';
                        continue;
                    }
                    $service_record['position'] = $plantilla_record['service_record']['position_id'];
                    $service_record['salary'] = $plantilla_record['service_record']['salary'];
                    $service_record['rate_type'] = $plantilla_record['service_record']['rate_type'];
                }
            }



            // Why is there no error handling here in case there is no service record present?

            /*
             * Insert payroll information
             */
            // Initialize date today
            $date_today = date("Y-m-d");

            /*
             * Initialize reference code variables
             *
             * Branch Code = first character of branch
             * Department Code = first character of department
             * Day Today = day in digits
             * Date Code = first digit of year and month, respectively
             */
            $branch_code = mb_substr($employee['branch'], 0, 1, 'UTF8');
            $department_code = mb_substr($employee['department'], 0, 1, 'UTF8');
            $day_today = date("d");
            $date_code = date("Ym");
            // $year_today = substr(date("Ymd"), -2);

            // Check if there are custom late/undertime/overbreak rules
            if ($params['late_rules'] === "[]" && $params['undertime_rules'] === "[]" && $params['overbreak_rules'] === "[]") {
                $customConfig = null;
            } else {
                // Parse custom config rules
                $custom = end($this->configuration['payroll_config']);
                $customConfig = json_encode($custom, JSON_NUMERIC_CHECK);
            }

            // Build reference code
            for ($x = 0; $x <= 10; $x++) {
                $reference_code = "$branch_code$department_code-$employee_id-$day_today-$date_code-$x";
            }

            $tax_helper = new TaxHelper($this, $employee_id, $start_date);
            $tax_witheld = $tax_helper->computeWitholdingTax($service_record, $params);

            // Set payroll information
            $payroll_info = array(
                'employee_id' => $employee_id,
                'month' => $params['month'],
                'year' => $params['year'],
                'reference_code' => $reference_code,
                'generated_date' => $date_today,
                'payroll_start_date' => $params['start_date'],
                'payroll_end_date' => $params['end_date'],
                'payroll_schedule' => $params['payroll_schedule'],
                'term' => $params['term'],
                'department_id' => $employee['latest_primary_department'],
                'branch_id' => $employee['latest_primary_branch'],
                'position_id' => $service_record['position'],
                'basic_salary' => $service_record['salary'],
                'late_rules' => $params['late_rules'],
                'undertime_rules' => $params['undertime_rules'],
                'overbreak_rules' => $params['overbreak_rules'],
                'custom_configuration' => $customConfig,
                'is_deleted' => 0,
                'tax_withheld' => $tax_witheld['income_tax'],
            );

            // Insert payroll information
            //$this->db->where('payroll_id',);  payroll_id
            $payroll_id = $this->db->insert('pr_information', $payroll_info);

            if ($this->db->getLastErrno() !== 0) {
                $this->Logger->LogError("[ERROR] | Insertion [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
            }


            // Why is there no error handling here in case the query fails?

            // Set payroll configuration to new variable
            $track_config = end($this->configuration['payroll_config']);

            // Check if there are late rules used
            if ($track_config['late_rules'] === []) {
                $used_late_rule = "[]";
            } else {
                $used_late_rule = json_encode($track_config['late_rules']);
            }

            // Check if there are undertime rules used
            if ($track_config['undertime_rules'] === []) {
                $used_undertime_rule = "[]";
            } else {
                $used_undertime_rule = json_encode($track_config['undertime_rules']);
            }

            // Check if there are overbreak rules used
            if ($track_config['overbreak_rules'] === []) {
                $used_overbreak_rule = "[]";
            } else {
                $used_overbreak_rule = json_encode($track_config['overbreak_rules']);
            }

            // Set configuration information
            $config_used = array(
                'payroll_id' => $payroll_id,
                'payroll_configuration_id' => $track_config['payroll_configuration_id'],
                'branch_id' => $track_config['branch_id'],
                'late_rules' => $used_late_rule,
                'undertime_rules' => $used_undertime_rule,
                'overbreak_rules' => $used_overbreak_rule,
                'employment_status' => $track_config['employment_status'],
                'rates' => $track_config['rates'],
                'enable_night_diff' => $track_config['enable_night_diff'],
                'night_diff_rate' => $track_config['night_diff_rate'],
                'regular_overtime_percentage' => $track_config['regular_overtime_percentage'],
                'regular_overtime_excess_percentage' => $track_config['regular_overtime_excess_percentage'],
                'regular_overtime_night_diff_percentage' => $track_config['regular_overtime_night_diff_percentage'],
                'rest_day_percentage' => $track_config['rest_day_percentage'],
                'rest_day_excess_percentage' => $track_config['rest_day_excess_percentage'],
                'rest_day_night_diff_percentage' => $track_config['rest_day_night_diff_percentage'],
                'regular_holiday_percentage' => $track_config['regular_holiday_percentage'],
                'regular_holiday_excess_percentage' => $track_config['regular_holiday_excess_percentage'],
                'regular_holiday_night_diff_percentage' => $track_config['regular_holiday_night_diff_percentage'],
                'special_holiday_percentage' => $track_config['special_holiday_percentage'],
                'special_holiday_excess_percentage' => $track_config['special_holiday_excess_percentage'],
                'special_holiday_night_diff_percentage' => $track_config['special_holiday_night_diff_percentage'],
                'daily_for_monthly_semi_basic_salary_dx' => $track_config['daily_for_monthly_semi_basic_salary_dx'],
                'daily_for_monthly_semi_basic_salary_dy' => $track_config['daily_for_monthly_semi_basic_salary_dy'],
                'daily_for_monthly_weekly_basic_salary_dx' => $track_config['daily_for_monthly_weekly_basic_salary_dx'],
                'daily_for_monthly_weekly_basic_salary_dy' => $track_config['daily_for_monthly_weekly_basic_salary_dy'],
                'allowance_include_period_absence_for_monthly' => $track_config['allowance_include_period_absence_for_monthly'],
                'allowance_include_lates_for_monthly' => $track_config['allowance_include_lates_for_monthly'],
                'allowance_include_undertime_for_monthly' => $track_config['allowance_include_undertime_for_monthly'],
                'allowance_include_period_absence_for_non_monthly' => $track_config['allowance_include_period_absence_for_non_monthly'],
                'allowance_include_lates_for_non_monthly' => $track_config['allowance_include_lates_for_non_monthly'],
                'allowance_include_undertime_for_non_monthly' => $track_config['allowance_include_undertime_for_non_monthly'],
                'deduct_benefits_for_no_earnings' => $track_config['deduct_benefits_for_no_earnings'],
                'deduct_loans_for_no_earnings' => $track_config['deduct_loans_for_no_earnings'],
                'allow_unposted_leave' => $track_config['allow_unposted_leave'],
                'allow_unposted_overtime' => $track_config['allow_unposted_overtime'],
                'consider_no_timein_timeout_beetween_logs' => $track_config['consider_no_timein_timeout_beetween_logs'],
                'as_of_date' => $track_config['as_of_date'],
                'end_date' => $track_config['end_date'],
                'created_by' => $track_config['created_by'],
                'created_date' => $track_config['created_date'],
                'rest_day_special_holiday_percentage' => $track_config['rest_day_special_holiday_percentage'],
                'special_holiday_overtime_percentage' => $track_config['special_holiday_overtime_percentage'],
                'regular_holiday_overtime_percentage' => $track_config['regular_holiday_overtime_percentage'],
                'rest_day_overtime_percentage' => $track_config['rest_day_overtime_percentage']
            );

            /** Set Combine Late Periods */
            !isset($config_used['combine_late_periods']) && $config_used['combine_late_periods'] = "0";

            // Insert payroll configuration (for later use during re-generation)
            $this->db->insert('pr_configuration', $config_used);

            /**
             * Logger
             */
            if ($this->db->getLastErrno() !== 0) {
                $this->Logger->LogError("[ERROR] | Insertion [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
            }

            // Why is there no error handling here in case the query fails?

            // Get holidays
            $this->holidays = $dtr_getter->getHolidays($prev_start_day, $last_day, $employee_id, $branch_departments['0']['branch_id']);

            /**
             * LAST PROGRESS HERE, THIS SHALL SERVE AS A MARKER
             */

            // Compute daily in payroll
            $daily = $this->computeDaily(
                $employee_id,
                $start_date,
                $end_date,
                $this->employee_schedule,
                $this->employee_service_records,
                $this->holidays,
                $this->employee_leaves,
                $this->employee_dtr,
                $dtr_getter,
                $branch_schedule_helper,
                $branch_departments,
                $payroll_id,
                $this->employee_overtime, //ot
                $payroll_config,
            );

            /**
             * LAST PROGRESS HERE, THIS SHALL SERVE AS A MARKER
             *
             * P.S. if you jumped in to computeDaily() and went back here, congrats.
             */

            // Check if there is/are suspension(s) and missing log(s)
            if ($daily['has_suspension'] && $daily['has_missing_log']) {
                $remarks = "With suspension and with missing logs";
            } else {
                // Computation is successful at this point
                $remarks = "Success";

                // Check if there is/are suspension(s)
                if ($daily['has_suspension']) {
                    $remarks = " With suspension";
                }

                // Check if there is/are missing log(s)
                if ($daily['has_missing_log']) {
                    $remarks = " With missing logs";
                }
            }

            /** Add First Period Hours */
            foreach ($daily['date_information'] as &$key) {
                !isset($key['first_period_hours']) && $key['first_period_hours'] = "0";
            }

            // Insert every single date information to database
            $this->db->insertMulti('pr_date_information', $daily['date_information']);

            /**
             * Logger
             */
            if ($this->db->getLastErrno() !== 0) {
                $this->Logger->LogError("[ERROR] | InsertMulti [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
            }

            /**
             * Why is there no checker here if the query was successful?
             */

            // Get leaves and update payroll information
            $this->getPayrollLeaves($employee_id, $start_date, $end_date, $payroll_id);

            /**
             * ot/holidays/regular pays (?)
             */

            // Bind/Initialize Employee Overtime/Holidays
            $employee_overtime_holidays = isset($this->employee_overtime[$employee_id]) ? $this->employee_overtime[$employee_id] : array();

            // Merge employee overtime and holiday containers into one
            $employee_overtime_holidays = array_merge($employee_overtime_holidays, $daily['employee_holidays']);

            // Get Overtime and Holidays from Payroll Information
            $overtime = $this->getPayrollOvertimeHolidays(
                $employee_id,
                $daily['payroll_info'],
                $employee_overtime_holidays,
                $dtr_getter,
                $branch_schedule_helper,
                $payroll_id,
                $branch_departments['0']['branch_id'],
                $payroll_config,
            );

            /**
             * Insert Overtimes
             *
             * Check if there are overtimes
             */
            if (count($overtime['overtime']) > 0) {
                // Initialize overtime list container
                $overtime_list = [];

                // Loop through entire overtimes list
                foreach ($overtime['overtime'] as $keys => &$ot_val) {
                    // Bind result to temporary variable
                    $test = $ot_val['result'];

                    // Add result to overtime list container
                    array_push($overtime_list, $test);

                    // Bind container to another container
                    $overtime_final = [
                        "overtime" => $overtime_list
                    ];
                }

                // Bind finalized container to variable
                $overtime_value = $overtime_final['overtime'];

                // Insert entire container to payroll overtime table
                $this->db->insertMulti('pr_overtime', $overtime_value);

                if ($this->db->getLastErrno() !== 0) {
                    $this->Logger->LogError("[ERROR] | InsertMulti [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
                }

                // UPDATE PAYROLL_ID ON EMP_OTHOLIDAY
                if (count($overtime_value)) {
                    $payroll_id = $overtime_value[0]['payroll_id'];
                    $this->db->where('employee_id', $employee_id);
                    $this->db->update('emp_otholiday', ['payroll_id' => $payroll_id,]);

                    if ($this->db->getLastErrno() !== 0) {
                        $this->Logger->LogError("[ERROR] | Update [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
                    }
                }

                /**
                 * Why is there no checker here if the insertion is successful?
                 */
            }

            /*
             * Total Earnings = Total Regular Overtimes + Total Overtime during Holidays + Daily Total Night Differentials + Daily Total Wage
             */
            $total_ot_on_holiday = 0;
            foreach ($overtime['ot_on_holidays'] as $ot_on_holiday) {
                $total_ot_on_holiday += $ot_on_holiday['ot_holiday_amount'];
            }

            $total_earnings = $overtime['total_regular'] + $total_ot_on_holiday + $daily['total_night_differential'] + $daily['total_wage'];

            /*
             * Total Statutory Deductions = Total Lates + Total Undertimes
             */
            $total_statutory_deductions = $daily['total_lates'] + $daily['total_undertime'];

            // Initialize total deductions to 0 (why tho)
            $total_deductions = 0;

            /*
             * Total Take Home Pay = Total Earnings - (Total Statutory Deductions - Total Deductions)
             */
            $total_take_home_pay = $total_earnings - ($total_statutory_deductions + $total_deductions);

            // Build finalized payroll results container
            $payroll[$employee_id] = array(
                'payroll_id' => $payroll_id,
                'employee_id' => $employee_id,
                'total_earnings' => $total_earnings,
                'total_statutory_deductions' => $total_statutory_deductions,
                'total_deductions' => $total_deductions,
                'employee' => $employee['name'],
                'daily' => $daily,
                'overtime' => $overtime,
                'loans' => array(
                    'total_amount' => 0,
                    'loans' => array()
                ),
                'benefits' => array(
                    'total_amount' => 0,
                    'benefits' => array()
                ),
                'adjustments' => array(
                    'total_additionals' => 0,
                    'total_deductions' => 0,
                    'adjustments' => array()
                ),
                'allowance' => array(
                    'total_amount' => 0,
                    'allowances' => array()
                ),
                'tax' => 0,
                'basic_salary' => $daily['total_basic_salary'],
                'remarks' => $remarks,
                'emp_payroll' => $emp_payroll
            );

            /**
             * Validate Unposted Leaves
             */

            // Initialize employee leaves container
            $employee_leaves = isset($this->employee_leaves[$employee_id]) ? $this->employee_leaves[$employee_id] : array();

            // Get previous unposted leaves and bind to payroll results
            $payroll[$employee_id]['unposted'] = $this->getpreviousUnpostedLeave($employee_leaves, $employee_id, $payroll_id, $dtr_getter);

            // Add total previous unposted leaves amount to payroll results as additionals to adjustments
            $payroll[$employee_id]['adjustments']['total_additionals'] += $payroll[$employee_id]['unposted']['total_amount'];

            // Add total previous unposted leaves amount to total earnings
            $payroll[$employee_id]['total_earnings'] += $payroll[$employee_id]['unposted']['total_amount'];

            // Set flag to success (phew)
            $status = "success";
        }

        // Get allowance and update payroll information
        $payroll = $this->getPayrollAllowance($this->employee_branches['employees'], $start_date, $end_date, $params['term'], $payroll);

        // Get benefits and update payroll information
        $payroll = $this->getPayrollAdjustments($this->employee_branches['employees'], (int) $params['month'], $params['year'], $params['term'], $payroll);

        // Get benefits and update payroll information
        $payroll = $this->getPayrollBenefits($this->employee_branches['employees'], $start_date, $end_date, $params['term'], $params['payroll_schedule'], $payroll);

        // Get loans and update payroll information
        $payroll = $this->getPayrollLoans($this->employee_branches['employees'], $end_date, $params['term'], $payroll);

        /**
         * If you're reading this, congratulations. You made it.
         */
        return array(
            'success' => $payroll,
            'non_success' => $non_success,
            'message' => $message,
            'status' => $status
        );
    }

    /**
     * Delete Existing Payroll Record
     *
     * @param string $employee_id
     * @return array $params
     * @throws \Exception
     */
    public function deleteExisted(string $employee_id, $params)
    {
        $this->db->where("employee_id", $employee_id);
        $this->db->where("payroll_id", $params['payroll_id']);
        $this->db->where("month", ((int) $params['month']));
        $this->db->where("year", $params['year']);
        $this->db->where("term", $params['term']);
        $this->db->where("is_deleted", 0);
        $this->db->where("payroll_schedule", $params['payroll_schedule']);
        $queryResult = $this->db->get("pr_information");

        return ($this->db->count > 0);
    }

    /**
     * Check payroll record
     *
     * @param string $employee_id
     * @return array $params
     * @throws \Exception
     */
    public function checkPayrollRecord($employee_id, $params)
    {
        $this->db->where("employee_id", $employee_id);
        $this->db->where("month", ((int) $params['month']));
        $this->db->where("year", $params['year']);
        $this->db->where("term", $params['term']);
        $this->db->where("is_deleted", 0);
        $this->db->where("payroll_schedule", $params['payroll_schedule']);
        $queryResult = $this->db->get("pr_information");

        return ($this->db->count > 0);
    }

    /**
     * Compute Daily Payroll
     *
     * @TODO break down this stupidly monolithic bigass function
     *
     * @param string $employee_id
     * @return array
     * @throws \Exception
     */
    public function computeDaily(
        $employee_id,
        $start_date,
        $end_date,
        $employee_schedules,
        $employee_service_records,
        $cutoff_holidays,
        $employee_leaves,
        $employee_dtr,
        $dtr_getter,
        $branch_schedule_helper,
        $branch_departments,
        $payroll_id,
        $overtime,
        $payroll_config,
    ) {
        // Initialize date interval
        $interval = new DateInterval('P1D');

        // Initialize start date
        $start = new DateTime($start_date);

        // Initialize end date
        $end = new DateTime($end_date);

        // Add interval to end date
        $end->add($interval);

        // Initialize payroll period using start, interval and end
        $payroll_period = new DatePeriod($start, $interval, $end);

        // Initialize containers
        $leave = array();
        $employee_holidays = array();
        $payroll_info = array();
        $dtr_on_prev_date = array();
        $working_hours = array();
        $date_information = array();

        // Initialize booleans
        $first_date = true;
        $has_suspension = false;
        $has_missing_log = false;

        // Initialize ID container
        $latest_primary_branch_department = 0;

        // Initialize counters
        $total_lates = 0;
        $total_undertime = 0;
        $total_overbreak = 0;
        $total_absences = 0;
        $total_basic_salary = 0;
        $total_wage = 0;
        $total_night_differential = 0;

        // Loop each date within the payroll period
        foreach ($payroll_period as $dt) {
            // Format date to YYYY-MM-DD
            $date = $dt->format('Y-m-d');

            // Initialize next date
            $next_date = new DateTime($date);

            // Increment 1 day
            $next_date->modify('+1 day');

            // Set incremented date as next day
            $next_day = $next_date->format('Y-m-d');

            // Initialize previous date
            $previous_date = new DateTime($date);

            // Decrement 1 day
            $previous_date->modify('-1 day');

            // Set decremented date as previous day
            $previous_day = $previous_date->format('Y-m-d');

            // Bind dates to payroll information
            $payroll_info[$date]['date'] = $date;
            $payroll_info[$date]['next_day'] = $next_day;
            $payroll_info[$date]['previous_day'] = $previous_day;

            // Initialize daily pay info container
            $daily_pay_info = array();

            // Get branch department record information
            $branch_department_info = $branch_schedule_helper->getEmployeeBranchDepartmentRecordInfo($branch_departments, $date);

            // Check if there is a record
            if ($branch_department_info['result']) {
                // Check if the record is the latest
                if ($branch_department_info['branch_department']['latest_primary']) {
                    // Bind department ID to variable
                    $latest_primary_branch_department = $branch_department_info['branch_department']['department_id'];
                }
            }

            // Bind department ID using branch department info if it exists
            $department_id = $branch_department_info['result'] ? $branch_department_info['branch_department']['department_id'] : 0;

            // Bind branch ID using branch department info if it exists
            $branch_id = $branch_department_info['result'] ? $branch_department_info['branch_department']['branch_id'] : 0;

            // Initialize daily pay info array
            $daily_pay_info = $this->setDailyPayInfo($employee_id, $date, $department_id, $payroll_id);

            // Bind daily pay info to payroll info
            $payroll_info[$date]['daily_pay_info'] = $daily_pay_info;

            // Get employee schedule and memo
            $emp_sched_memo = $branch_schedule_helper->findEmployeeSchedule($employee_id, $date, $employee_schedules);

            // Bind employee schedule to variable
            $emp_schedule = $emp_sched_memo['schedule'];

            // Bind employee memo to variable
            $emp_memo = $emp_sched_memo['memo'];

            // Bind employee schedule to payroll info
            $payroll_info[$date]['emp_sched_memo'] = $emp_sched_memo;

            /**
             * LAST PROGRESS HERE, THIS SHALL SERVE AS A MARKER
             */

            // Check if there are employee schedules bound to employee ID
            if (!isset($employee_schedules[$employee_id])) {
                continue;
            }

            /**
             * Check Travel Order
             */

            // Initialize travel order container
            $travel_order = array("result" => false, "data" => array());

            // Check if memo has travel order attached
            if ($emp_memo["has_travel_order"]) {
                // Set travel order result to true
                $travel_order["result"] = true;

                /** Note: Schedule key went missing. */
                $schedule_key = $dt->format('Y-m-d');

                // Bind travel order data from employee schedule data to travel order result
                $travel_order["data"] = $employee_schedules[$employee_id][$schedule_key]["memo"]["has_travel_order_data"];
            }

            // Bind travel order info to payroll info
            $payroll_info[$date]['travel_order'] = $travel_order;

            /**
             * Check Revoke Holiday
             */

            // Initialize suspension order container
            $revoke_holiday = array("result" => false, "data" => array());

            // Check if memo has suspension order attached
            if ($emp_memo["holiday_revoke"]) {
                // Set has_suspension boolean to true
                /** Temporarily comment-out due to error! */
                // $holiday_revoke = true;

                // Set travel order result to true
                $revoke_holiday["result"] = true;

                /** Note: Schedule key went missing. */
                $schedule_key = $dt->format('Y-m-d');

                // Bind suspension order data from employee schedule data to suspension order result
                $revoke_holiday["data"] = $employee_schedules[$employee_id][$schedule_key]["memo"]["holiday_revoke_data"];
            }

            // Bind travel order info to payroll info
            $payroll_info[$date]['holiday_revoke'] = $revoke_holiday;

            /**
             * Check Suspension Order
             */

            // Initialize suspension order container
            $suspension_order = array("result" => false, "data" => array());

            // Check if memo has suspension order attached
            if ($emp_memo["has_suspension"]) {
                // Set has_suspension boolean to true
                /** Temporarily comment-out due to error! */
                // $has_suspension = true;

                // Set travel order result to true
                $suspension_order["result"] = true;

                /** Note: Schedule key went missing. */
                $schedule_key = $dt->format('Y-m-d');

                // Bind suspension order data from employee schedule data to suspension order result
                $suspension_order["data"] = $employee_schedules[$employee_id][$schedule_key]["memo"]["suspension_data"];
            }


            // Bind travel order info to payroll info
            $payroll_info[$date]['suspension_order'] = $suspension_order;

            // Check if suspension order result is true
            if ($suspension_order['result']) {
                // Set suspended flag in payroll info to 1
                $payroll_info[$date]['is_suspended'] = 1;
                continue;
            }

            // Bind Override info to payroll info
            $override_order = array("result" => false, "data" => array());

            if (array_key_exists('has_schedule_override', $emp_memo)) {
                if ($emp_memo["has_schedule_override"]) {
                
                    // Set travel order result to true
                    $override_order["result"] = true;

                    /** Note: Schedule key went missing. */
                    $schedule_key = $dt->format('Y-m-d');

                    // Bind suspension order data from employee schedule data to suspension order result
                    $override_order["data"] = $employee_schedules[$employee_id][$schedule_key]["memo"]["override"];
                }

                $payroll_info[$date]['has_schedule_override'] = $override_order;
            }
           

            // Get employee next day schedule memo(?) (it's exactly the same from the one above. why tho)
            $emp_next_day_sched_memo = $branch_schedule_helper->findEmployeeSchedule($employee_id, $date, $employee_schedules);

            // Bind employee next day schedule memo(?) to variable (again)
            $emp_schedule_next = $emp_next_day_sched_memo['schedule'];

            // Bind employee next day schedule memo(?) to variable (again)
            $emp_schedule_next_memo = $emp_next_day_sched_memo['memo'];

            // Bind employee next day schedule memo(?) to payroll info using different index (at this point, hmmmm, why)
            $payroll_info[$date]['emp_next_day_sched_memo'] = $emp_next_day_sched_memo;

            // Initialize working hours counters
            $total_working_hours = 0;
            $first_period_working_hours = 0;
            $second_period_working_hours = 0;

            // Check if employee has schedule based from memo
            if ($emp_schedule != "noSched") {
                /**
                 * Bind Total Working Hours, First Period Working Hours and Second Period Working Hours to variables
                 *
                 * Total Working Hours = First Period Working Hours + Second Period Working Hours
                 */
                if (isset($emp_schedule['logins']['second_period_working_hours'])) {
                    $total_working_hours = $emp_schedule['logins']['first_period_working_hours'] + $emp_schedule['logins']['second_period_working_hours'];
                    $first_period_working_hours = $emp_schedule['logins']['first_period_working_hours'];
                    $second_period_working_hours = $emp_schedule['logins']['second_period_working_hours'];
                } else {
                    $total_working_hours = $emp_schedule['logins']['first_period_working_hours'];
                    $first_period_working_hours = $emp_schedule['logins']['first_period_working_hours'];
                    $second_period_working_hours = 0;
                }
                
            } else {
                // Check if employee schedules parameter has rest day pay
                if (array_key_exists('rest_day_pay', $employee_schedules[$employee_id])) {
                    /**
                     * Bind Total Working Hours, First Period Working Hours and Second Period Working Hours to variables
                     *
                     * Total Working Hours = Rest Day Pay
                     */
                    if (isset($employee_schedules[$employee_id]['first_period_working_hours']) && isset($employee_schedules[$employee_id]['second_period_working_hours'])) {
                        $total_working_hours = $employee_schedules[$employee_id]['rest_day_pay'];
                        $first_period_working_hours = $employee_schedules[$employee_id]['first_period_working_hours'];
                        $second_period_working_hours = $employee_schedules[$employee_id]['second_period_working_hours'];
                    }
                    
                }
            }

            // Bind Total Working Hours, First Period Working Hours and Second Period Working Hours to payroll info
            $payroll_info[$date]['total_working_hours'] = $total_working_hours;
            $payroll_info[$date]['first_period_working_hours'] = $first_period_working_hours;
            $payroll_info[$date]['second_period_working_hours'] = $second_period_working_hours;

            /**
             * LAST PROGRESS HERE, THIS SHALL SERVE AS A MARKER
             */

            if (isset($payroll_config['payroll_type']) && $payroll_config['payroll_type'] === 1) { // * GOVERNMENT
                $service = $branch_schedule_helper->getPlantillaRecord($employee_id, $date);
                if (!$service['result']) {
                    continue;
                } // ? CONTINUE TO THE NEXT DATE
            } else {
                // CHeck if has service records
                if (!isset($employee_service_records[$employee_id])) {
                    // Proceed to next date
                    continue;
                }
                // Get employee service records
                $service = $branch_schedule_helper->getEmployeeServiceRecordInfo($employee_service_records[$employee_id], $date, $total_working_hours);
            }

            // Bind service record to payroll info
            $payroll_info[$date]['service'] = $service;

            // Check if there is no service record
            if (!$service["result"]) {
                // Proceed to next date
                continue;
            }

            // Bind holiday information to variable if there is one based on date
            $holiday_in_date = isset($cutoff_holidays[$date]) ? $cutoff_holidays[$date] : array();

            // Bind holiday information to payroll info
            $payroll_info[$date]['holiday_in_date'] = $holiday_in_date;

            // Check if first date has value and if there are holidays
            if ($first_date && COUNT($holiday_in_date) > 0) {
                // Bind DTR information from payroll to variable
                $dtr_on_prev_date[$employee_id][$previous_day] = $this->checkDtrPayroll($employee_id, $previous_day);
            }

            // Initialize new date variable
            $dt = new DateTime($date);

            /**
             * Compute total rate, first period rate and second period rate
             *
             * Rate = See Function Below
             * First Period Rate = First Period Working Hours * Hourly Rate from Service Record
             * Second Period Rate = Second Period Working Hours * Hourly Rate from Service Record
             */
            $service["service_record"]["rate"] = $this->rateComputationsforBasicsalary($branch_id, $service["service_record"]['salary'], $service["service_record"]['rate_type'], $total_working_hours);
            $service['service_record']['first_period_rate'] = $first_period_working_hours * $service['service_record']['rate']['hRate'];
            $service['service_record']['second_period_rate'] = $second_period_working_hours * $service['service_record']['rate']['hRate'];

            // Set Paid Rest Day to false
            $rest_day_paid = false;

            // Bind rates configuration from payroll configuration to variable
            $rates_config = json_decode($this->configuration['payroll_config'][$branch_id]['rates'], true);

            /**
             * Set Paid Rest Day based on service record rate type and rates configuration
             *
             * For every instance that rest day is paid, whether monthly, daily or hourly,
             * Paid Rest Day is set to true
             */
            switch ($service["service_record"]['rate_type']) {
                case 0:
                    // Monthly Raters
                    if ($rates_config['monthly']['restDayPaid'] == 1) {
                        $rest_day_paid = true;
                    }
                    break;
                case 1:
                    // Daily Raters
                    if ($rates_config['daily']['restDayPaid'] == 1) {
                        $rest_day_paid = true;
                    }
                    break;
                case 2:
                    // Hourly Raters
                    if ($rates_config['hourly']['restDayPaid'] == 1) {
                        $rest_day_paid = true;
                    }
                    break;
            }

            // Bind Paid Rest Day information to payroll info
            $payroll_info[$date]['rest_day_paid'] = $rest_day_paid;

            // Initialize time-in and leave checkers
            $first_time_in_present = false;
            $second_time_in_present = false;
            $has_leave_in_first_period = false;
            $has_leave_in_second_period = false;

            // Initialize credits
            $total_period_credits = 0;

            /**
             * Set DTR on previous date to true based on date
             *
             * for Monthly , Daily , Hourly (?) (dunno what this line means)
             */
            $dtr_on_prev_date[$employee_id][$date] = true;

            // Set rest day flag to true in payroll info
            $payroll_info[$date]['is_rest_day'] = true;

            // Check if working housrs based on total working hours is present
            if (!isset($working_hours[$total_working_hours])) {
                /**
                 * Initialize working hours container for allowance computation
                 */
                $working_hours[$total_working_hours]['total_leave_credits_used'] = 0;
                $working_hours[$total_working_hours]['total_paid_days'] = 0;
                $working_hours[$total_working_hours]['hours'] = $total_working_hours;
                $working_hours[$total_working_hours]['branch_id'] = $branch_id;
                $working_hours[$total_working_hours]['dates'] = array();
            }

            /**
             * LAST PROGRESS HERE, THIS SHALL SERVE AS A MARKER
             *
             * IF YOU READ THIS, KNOW THAT THE PERSON WHO WROTE THIS COMMENT IS ALREADY ANNOYED AT THIS POINT
             */

            // Bind date to total working hours dates
            $working_hours[$total_working_hours]['dates'][$date] = $date;

            // Check if employee has schedule
            if ($emp_schedule != "noSched") {
                // Bind employee DTR if employee has DTR, otherwise initialize
                $employee_dtr[$employee_id] = isset($employee_dtr[$employee_id]) ? $employee_dtr[$employee_id] : array();

                // Set payroll info for rest day to false
                $payroll_info[$date]['is_rest_day'] = false;

                // Get employee DTR logs
                $log_sets = $dtr_getter->getEmployeeDtrLogs($date, $next_day, $emp_schedule, $employee_dtr[$employee_id], $emp_schedule_next, true);

                // Check if employee has travel order
                if ($travel_order["result"]) {
                    //if employee has TO

                    // why blank tho
                }

                // Why is there no checker here if there is no employee DTR logs?

                // Bind log sets to payroll info
                $payroll_info[$date]['log_set'] = $log_sets;

                // Find leave for first and second periods
                $first_period_leave = $dtr_getter->findLeaveInPeriod($date, "0", $employee_id, $employee_leaves);
                $second_period_leave = $dtr_getter->findLeaveInPeriod($date, "1", $employee_id, $employee_leaves);

                // Initialize evaluations criteria container
                $to_evaluate = array(
                    'lates' => true,
                    'period_breaks' => true,
                    'night_diff' => true,
                    'undertime' => true,
                    'overtime' => true,
                    'other_breaks' => true
                );

                // Evaluate DTR sets using evaluation criteria
                $evaluation = $dtr_getter->evaluateDtrSets(
                    $to_evaluate,
                    $date,
                    $next_day,
                    $emp_schedule,
                    $log_sets["logs"]
                );

                // Bind evaluation to payroll info
                $payroll_info[$date]['evaluation'] = $evaluation;

                // Get employee lates from evaluation
                $lates = $this->getEmployeeLates($evaluation, $service, $emp_schedule, $branch_id, $date);

                // Bind lates information to payroll info
                $payroll_info[$date]['lates'] = $lates;

                // Get employee undertimes from evaluation
                $undertime = $this->getEmployeeUndertime($evaluation, $service, $emp_schedule, $branch_id, $date);

                // Bind undertimes information to payroll info
                $payroll_info[$date]['undertime'] = $undertime;

                // Get employee overbreak from evaluation
                $period_breaks = $this->getEmployeeOverbreak($evaluation, $service, $emp_schedule, $branch_id, $date, $log_sets['logs']['break']);

                // Bind overbreaks information to payroll info
                $payroll_info[$date]['period_breaks'] = $period_breaks;

                // Get employee night differential
                $night_diff = $this->getEmployeeNightDifferential($evaluation, $service, $branch_id);

                //compute for Night Diff for holidays

                foreach ($holiday_in_date as $hid) {
                    if (isset($hid['is_active']) && $hid['is_active'] === 1 && $hid['date'] === $date) {
                        switch ($hid['type']) {
                            case 0:
                                $night_diff['first']['amount'] *= ($this->configuration['payroll_config'][$branch_id]['special_holiday_percentage'] + 100) / 100;
                                $night_diff['second']['amount'] *= ($this->configuration['payroll_config'][$branch_id]['special_holiday_percentage'] + 100) / 100;
                                break;
                            case 1:
                                $night_diff['first']['amount'] *= ($this->configuration['payroll_config'][$branch_id]['regular_holiday_percentage'] + 100) / 100;
                                $night_diff['second']['amount'] *= ($this->configuration['payroll_config'][$branch_id]['regular_holiday_percentage'] + 100) / 100;
                                break;
                        }
                    }
                }

                // Bind night differential information to payroll info
                $payroll_info[$date]['night_diff'] = $night_diff;

                /**
                 * LAST PROGRESS HERE, THIS SHALL SERVE AS A MARKER
                 */

                // Get employee absence
                $absence = $this->getEmployeeAbsence($service);

                // Bind absence information to payroll info
                $payroll_info[$date]['absence'] = $absence;

                // Set computation of first period absence to false
                $compute_first_period_absence = false;

                // Check if DTR log sets has missing logs in first set
                if ($log_sets["logs"]["dtr"]["has_missing_in_first_set"]) {
                    // Set missing log flag in payroll info to 1
                    $payroll_info[$date]['missing_log'] = 1;

                    // Set computation of first period absence to true
                    $compute_first_period_absence = true;

                    // Set missing log flag to true
                    $has_missing_log = true;
                } else {
                    // Set first period leave override to false
                    $leave_first_period_overide = false;

                    /**
                     * Check leave for staight hours schedules
                     *
                     * Conditions:
                     * - Employee schedule is only one period
                     * - Employee has first period leave
                     * - Check if first period leave is set
                     * - Check if first period leave is paid
                     */
                    if (
                        $emp_schedule["period"] == "0" &&
                        $first_period_leave["result"] &&
                        isset($first_period_leave["leave"]["is_paid"]) &&
                        $first_period_leave["leave"]["is_paid"] == "1"
                    ) {
                        // Get leave schedule info for straight hours
                        $leave_sched_info = $this->checkLeaveForStraightHoursScheds($date, $first_period_leave["leave"]);

                        // Bind leave schedule information to payroll info
                        $payroll_info[$date]['leave_sched_info'] = $leave_sched_info;

                        // Set leave hours to half (why tho, probably to halve what is already in half)
                        $leave_hours = $first_period_working_hours / 2;

                        // Check if leave schedule info is set to update lates
                        if ($leave_sched_info == "updateLates") {
                            // Set override for first period leave to true
                            $leave_first_period_overide = true;

                            // Initialize total lates counter
                            $evaluation["1st"]["total_lates"] = 0;

                            // Check if total undertime for first period is greater than leave hours
                            if ($evaluation["1st"]["total_undertime"] > $leave_hours) {
                                // Update total undertime in evaluation by subtracting leave hours
                                $evaluation["1st"]["total_undertime"] -= $leave_hours;

                                // Bind total undertime evaluation result to first period undertime
                                $undertime['first']['undertime'] = $evaluation["1st"]["total_undertime"];

                                // Initialize undertime configuration array
                                $configuration_undertime = array(
                                    'employment_status' => json_decode($this->configuration['payroll_config'][$branch_id]['employment_status'], true)
                                );

                                // Compute undertime based on total undertime evaluation and undertime configuration
                                $undertime['first']['amount'] = $this->computeUndertime($evaluation["1st"]["total_undertime"], $undertime['first']['amount'], $service, $configuration_undertime['employment_status'], $undertime['first']['absent'], $undertime['first']['has_undertime_config']);
                            }

                            // Check if leave schedule info is set to update undertime
                        } elseif ($leave_sched_info == "updateUndertime") {
                            // Set first period override for leave to true
                            $leave_first_period_overide = true;

                            // Update total undertime in evaluation by subtracting leave hours
                            $evaluation["1st"]["total_undertime"] -= $leave_hours;

                            // Check if total undertime in evaluation is less than 0
                            if ($evaluation["1st"]["total_undertime"] < 0) {
                                // Set total undertime in evaluation to 0
                                $evaluation["1st"]["total_undertime"] = 0;

                                // Set undertime hours in first period to 0
                                $undertime['first']['undertime'] = 0;

                                // Set undertime amount in first period to 0
                                $undertime['first']['amount'] = 0;
                            }
                        }
                    }

                    // Bind first period leave override status to payroll info
                    $payroll_info[$date]['leave_first_period_overide'] = $leave_first_period_overide;

                    /**
                     * Proceed to Attendance Checking
                     *
                     * Check if first period late is not marked as absent
                     */
                    if (!$lates['first']["absent"]) {
                        // Check if there are DTR log sets for first period
                        if (COUNT($log_sets["logs"]["dtr"]["1st"]) > 0) {
                            // Set first period absence computation to false
                            $compute_first_period_absence = false;

                            // Set first time in present to true
                            $first_time_in_present = true;

                            // Set DTR on previous date to false
                            $dtr_on_prev_date[$employee_id][$date] = false;
                        } else {
                            // Check if it's not Holiday
                            if (!count($holiday_in_date)) {
                                /** Mark employee as absent if no travel order memo  */
                                if (!$payroll_info[$date]['travel_order']['result']) {
                                    // Set compute for first period absence to true
                                    $compute_first_period_absence = true;
                                }
                            }
                        }
                    } else {
                        // Set compute for first period absence to true
                        $compute_first_period_absence = true;
                    }

                    // Check if first period override for leave is set to true
                    if ($leave_first_period_overide) {
                        /**
                         * PRESS X TO DOUBT THIS CODE SECTION HERE
                         */
                        // Check if there are DTR log sets for first period
                        if (COUNT($log_sets["logs"]["dtr"]["1st"]) > 0) {
                            // Divide first period working hours to 2
                            $first_period_working_hours = $first_period_working_hours / 2;
                        } else {
                            // Divide first period working hours to 2
                            $first_period_working_hours = $first_period_working_hours / 2;
                        }
                    }
                }

                // Check if compute for first period absence is set to true
                if ($compute_first_period_absence) {
                    /**
                     * absent (?)
                     * compute absentism (?)
                     * absent 1st period (?)
                     * check for leave (?)
                     */

                    // Set first time in as present to false
                    $first_time_in_present = false;

                    // Bind first period working hours as first period absent hours
                    $absence['first']['absent_hour'] = $first_period_working_hours;

                    // Compute absence amount for first period
                    $absence['first']['amount'] = $this->computeAbsence($first_period_working_hours, $service);

                    // Check if first period is filed as leave
                    if ($first_period_leave["result"]) {
                        // Check if first period leave is filed as paid
                        if ($first_period_leave["leave"]["is_paid"] == "1") {
                            // Set DTR on previous date to false
                            $dtr_on_prev_date[$employee_id][$date] = false;
                            // $first_time_in_present = true;
                            $absence['first']['absent_hour'] = 0;
                            $absence['first']['amount'] = 0;
                        } else {
                            // $first_time_in_present = false;
                            $absence['first']['absent_hour'] = $first_period_working_hours;
                            $absence['first']['amount'] = $this->computeAbsence($first_period_working_hours, $service);
                        }
                    } else {
                        // $first_time_in_present = false;
                        $absence['first']['absent_hour'] = $first_period_working_hours;
                        $absence['first']['amount'] = $this->computeAbsence($first_period_working_hours, $service);
                    }
                }

                // Set compute for second period absence to false
                $compute_second_period_absence = false;


                /**
                 * For employee with 2nd period schedule
                 *
                 * Check if employee has second period in schedule
                 */
                if ($emp_schedule['period'] == '1') {
                    /**
                     * compute lates (?)
                     * insert missing logs (?)
                     */

                    // Check if DTR log sets has missing records in second set
                    if (($log_sets["logs"]["dtr"]["has_missing_in_second_set"])) {
                        // Set missing log flag in payroll info
                        $payroll_info[$date]['missing_log'] = 1;

                        // Set compute for second period absence to true
                        $compute_second_period_absence = true;

                        // Set missing log flag to true
                        $has_missing_log = true;
                    } else {
                        /**
                         * Proceed to Attendance Checking
                         *
                         * Check if second period late is not marked as absent
                         */
                        if (!$lates['second']["absent"]) {
                            // Check if there are DTR log sets for second period
                            if (COUNT($log_sets["logs"]["dtr"]["2nd"]) > 0) {
                                // Set second period absence computation to false
                                $compute_second_period_absence = false;

                                // Set second time in present to true
                                $second_time_in_present = true;

                                // Set DTR on previous date to false
                                $dtr_on_prev_date[$employee_id][$date] = false;
                            } else {
                                // Check if it's not Holiday
                                if (!count($holiday_in_date)) {
                                    /** Mark employee as absent if no travel order memo  */
                                    if (!$payroll_info[$date]['travel_order']['result']) {
                                        // Set compute for second period absence to true
                                        $compute_second_period_absence = true;
                                    }
                                }
                            }
                        } else {
                            // Set second period absence computation to true
                            $compute_second_period_absence = true;
                        }
                    }

                    if ($evaluation['2nd']['worked_hours'] === 0) {
                        $compute_second_period_absence = true;
                    }
                    // Check if compute for second period absence is set to true
                    if ($compute_second_period_absence) {
                        /**
                         * absent (?)
                         * compute absentism (?)
                         * absent 2nd period (?)
                         * check for leave (?)
                         */

                        // Set second time in as present to false
                        $second_time_in_present = false;

                        // Check if second period is filed as leave
                        if ($second_period_leave["result"]) {
                            // Check if second period leave is filed as paid
                            if ($second_period_leave["leave"]["is_paid"] == "1") {
                                // Set DTR on previous date to false
                                $dtr_on_prev_date[$employee_id][$date] = false;
                                // $second_time_in_present = true;
                                $absence['second']['absent_hour'] = 0;
                                $absence['second']['amount'] = 0;
                            } else {
                                // Bind second period working hours as first period absent hours
                                $absence['second']['absent_hour'] = $second_period_working_hours;

                                // Compute absence amount for second period
                                $absence['second']['amount'] = $this->computeAbsence($second_period_working_hours, $service);
                            }
                        } else {
                            // Bind second period working hours as first period absent hours
                            $absence['second']['absent_hour'] = $second_period_working_hours;

                            // Compute absence amount for second period
                            $absence['second']['amount'] = $this->computeAbsence($second_period_working_hours, $service);
                        }
                    }
                }

                //deduct absences in paid hours
                if (
                    count($holiday_in_date) &&
                    $holiday_in_date[0]['type'] === 1 &&
                    $holiday_in_date[0]['is_active'] === 1
                ) {
                    $paid_hours = $total_working_hours - ($absence['second']['absent_hour'] + $absence['first']['absent_hour']);

                    // Bind total working hours as paid working hours in payroll info
                    $payroll_info[$date]['daily_pay_info']['paid_hours'] = $paid_hours;
                } else {
                    $payroll_info[$date]['daily_pay_info']['paid_hours'] = $total_working_hours;
                }


                /**
                 * LAST PROGRESS HERE, THIS SHALL SERVE AS A MARKER
                 */

                /** I don't know what im doing... I'm desperate, Please HUG ME! */
                /** IF there is a revoke holiday memo, make "required_work" into 1(work-required) */
                if (count($holiday_in_date)) {
                    $holiday_in_date[0]['required_work'] = $revoke_holiday['result'] ? 1 : 0;
                }

                // Check if there is a holiday in date and if holiday exists in config
                if (
                    count($holiday_in_date) &&
                    (!array_key_exists('config', $holiday_in_date[0])) &&
                    ($holiday_in_date[0]['type'] == 1 && $holiday_in_date[0]['required_work'] == 0)
                ) {
                    //intialize to zero absent
                    //$absence = $this->getEmployeeAbsence($service);

                    /**
                     * ???
                     */
                    if (!$first_time_in_present) {
                        // ???
                    }

                    /**
                     * ???
                     */
                    if (!$second_time_in_present) {
                        // ???
                    }
                } else {
                    /**
                     * Conditions:
                     * - First period is filed as leave
                     * - Employee has not timed in for first period
                     */
                    if (
                        $first_period_leave['result'] != false &&
                        (isset($first_period_leave["result"]) && $first_period_leave["result"]) &&
                        !$first_time_in_present
                    ) {
                        // Set leave in first period to 1
                        $has_leave_in_first_period = 1;

                        // Check if leave is filed as paid
                        if ($first_period_leave["leave"]["is_paid"] != "1") {
                            // Subtract first period working hours from paid hours in payroll info
                            $payroll_info[$date]['daily_pay_info']['paid_hours'] -= $first_period_working_hours;
                        } else {
                            // Check if leave info is bound in employee leaves
                            if (isset($employee_leaves[$employee_id][$first_period_leave["leave"]["id"]])) {
                                /**
                                 * First Period Credits = First Period Working Hours / Total Working Hours
                                 */
                                $first_period_credits = $first_period_working_hours / $total_working_hours;

                                // Bind first period credits to payroll info
                                $payroll_info[$date]['first_period_credits'] = $first_period_credits;

                                // Bind first period leave balance to payroll info
                                $payroll_info[$date]['first_period']['remaining'] = $first_period_leave["leave"]["balance"];
                                $credits_used = floatval($employee_leaves[$employee_id][$first_period_leave["leave"]["id"]]["credits_used"]);

                                $leave_id = $first_period_leave["leave"]["id"];
                                $leave_start_date = new DateTime($employee_leaves[$employee_id][$leave_id]['start_date']);
                                $current_date = new DateTime($date);
                                $interval = $leave_start_date->diff($current_date)->days + 0.5;

                                // Check if employee leaves stil has balance (not 0) and leave credits consumption is less than or equal to leave credits balance
                                if ($credits_used >= $interval) {
                                    // Deduct first period credits from balance
                                    $employee_leaves[$employee_id][$leave_id]["balance"] -= $first_period_credits;

                                    // Add first period credits to total period credits
                                    $total_period_credits += $first_period_credits;

                                    // Check if first period leave container is initialized
                                    if (!isset($leave[$first_period_leave["leave"]["id"]])) {
                                        // Initialize leave container for first period
                                        $leave[$first_period_leave["leave"]["id"]] = array(
                                            "from_previous_cutoff" => "0",
                                            "payroll_id" => '',
                                            // ????????
                                            "credits" => 0,
                                            "amount" => 0,
                                            "leave_record_id" => $first_period_leave["leave"]["id"],
                                            "payroll_id" => $payroll_id
                                        );
                                    }

                                    // Add first period credits to leave container for first period credits
                                    $leave[$first_period_leave["leave"]["id"]]['credits'] += $first_period_credits;

                                    // Set leave period divisor to 1
                                    $leave_period_divisor = 1;

                                    // Check if first period leave override is set and no schedule info is set
                                    if (
                                        isset($leave_first_period_overide) &&
                                        $leave_first_period_overide &&
                                        $leave_sched_info != ''
                                    ) {
                                        /**
                                         * Recompute absence
                                         *
                                         * Set leave period divisor to 2
                                         */
                                        $leave_period_divisor = 2;

                                        // Add first period working hours to first period absent hours
                                        $absence['first']['absent_hour'] += $first_period_working_hours;

                                        /**
                                         * Absence Amount = Absence Amount + (First Period Rate (from service record) / Leave Period Divisor)
                                         */
                                        $absence['first']['amount'] += $service['service_record']['first_period_rate'] / $leave_period_divisor;

                                        // Subtract first period working hours from paid hours for current date in payroll info
                                        $payroll_info[$date]['daily_pay_info']['paid_hours'] -= $first_period_working_hours;
                                    } else {
                                        // Set absent hours to 0
                                        $absence['first']['absent_hour'] = 0;

                                        // Set amount to 0
                                        $absence['first']['amount'] = 0;
                                    }

                                    /**
                                     * Leave Amount = Leave Amount + (First Period Rate (from service record) / Leave Period Divisor)
                                     */
                                    $leave[$first_period_leave["leave"]["id"]]['amount'] += ($service['service_record']['first_period_rate'] / $leave_period_divisor);
                                } else {
                                    // Subtract first period working hours from paid hours in current date in payroll info
                                    $payroll_info[$date]['daily_pay_info']['paid_hours'] -= $first_period_working_hours;
                                }
                            }
                        }
                    } else {
                        // Check if first time in present is set
                        if ($first_time_in_present) {
                            // Check if first period late amount is greater than service record daily rate
                            if ($lates['first']['amount'] > $service['service_record']["rate"]["dRate"]) {
                                // Set first period undertime amount to zero
                                $undertime['first']['amount'] = 0;

                                // Set first period undertime hours to zero
                                $undertime['first']['undertime'] = 0;
                            }
                        } else {
                            /** alter paid_hours into hours given on travel order memo */
                            if ($payroll_info[$date]['travel_order']['result'] == true) {
                                $paid_hours_breakdown = json_decode($payroll_info[$date]['travel_order']['data']['paid_hours_breakdown'], true);

                                $payroll_info[$date]['daily_pay_info']['paid_hours'] = intval($paid_hours_breakdown[0]['hours']);
                            } else {
                                // Subtract first period working hours from paid hours in payroll info
                                $payroll_info[$date]['daily_pay_info']['paid_hours'] -= $first_period_working_hours;
                            }
                        }
                    }

                    // Check if period is second period
                    if ($emp_schedule['period'] == '1') {
                        // Check if second period leave is filed and employee did not time in for second period
                        if (
                            $second_period_leave['result'] != false &&
                            (isset($second_period_leave["result"]) && $second_period_leave["result"]) &&
                            !$second_time_in_present
                        ) {
                            // Set leave in second period flag to 1
                            $has_leave_in_second_period = 1;

                            // Check if second period leave is not paid
                            if ($second_period_leave["leave"]["is_paid"] != "1") {
                                // Deduct second period working hours from paid hours in payroll info
                                $payroll_info[$date]['daily_pay_info']['paid_hours'] -= $second_period_working_hours;
                            } else {
                                // Check if employee leave is set for second period
                                if (isset($employee_leaves[$employee_id][$second_period_leave["leave"]["id"]])) {
                                    /**
                                     * Second Period Credits = Working hours during period / Total Working Hours
                                     */
                                    $second_period_credits = $second_period_working_hours / $total_working_hours;

                                    // Bind second period credits to payroll info
                                    $payroll_info[$date]['second_period_credits'] = $second_period_credits;

                                    // Bind second period leave balance to second period remaining credits in payroll info
                                    $payroll_info[$date]['second_period']['remaining'] = $second_period_leave["leave"]["balance"];

                                    $credits_used = floatval($employee_leaves[$employee_id][$first_period_leave["leave"]["id"]]["credits_used"]);

                                    $leave_id = $first_period_leave["leave"]["id"];
                                    $leave_start_date = new DateTime($employee_leaves[$employee_id][$leave_id]['start_date']);
                                    $current_date = new DateTime($date);
                                    $interval = $leave_start_date->diff($current_date)->days;

                                    $leave_end_date = $employee_leaves[$employee_id][$leave_id]['end_date'];
                                    $end_period = $employee_leaves[$employee_id][$leave_id]['end_period'];
                                    if ($leave_end_date === $date && $end_period === 1) {
                                        $interval += 0.5;
                                    } else {
                                        $interval += 1;
                                    }

                                    // Check if employee still has leave credits and second period credits consumption is less than or equal to remaining credits
                                    if ($credits_used >= $interval) {
                                        // Deduct second period credits from employee leave credits
                                        $employee_leaves[$employee_id][$second_period_leave["leave"]["id"]]["balance"] -= $second_period_credits;

                                        // Add second period credits to total period credits
                                        $total_period_credits += $second_period_credits;

                                        // Check if second period leave is not yet initialized
                                        if (!isset($leave[$second_period_leave["leave"]["id"]])) {
                                            // Initialize second period leave container
                                            $leave[$second_period_leave["leave"]["id"]] = array(
                                                "from_previous_cutoff" => "0",
                                                "payroll_id" => '',
                                                // ???
                                                "credits" => 0,
                                                "amount" => 0,
                                                "leave_record_id" => $first_period_leave["leave"]["id"],
                                                "payroll_id" => $payroll_id
                                            );
                                        }

                                        // Add second period credits to leave credits
                                        $leave[$second_period_leave["leave"]["id"]]['credits'] += $second_period_credits;

                                        // Add second period rate from service record to leave amount
                                        $leave[$second_period_leave["leave"]["id"]]['amount'] += ($service['service_record']['second_period_rate']);
                                    } else {
                                        // Subtract first period working hours from paid hours in payroll info
                                        $payroll_info[$date]['daily_pay_info']['paid_hours'] -= $first_period_working_hours;
                                    }
                                }
                            }
                        } else {
                            // Check if second period time-in is present
                            if ($second_time_in_present) {
                                // Check if first period late amount is greater than daily rate in service record
                                if ($lates['first']['amount'] > $service['service_record']["rate"]["dRate"]) {
                                    // Set second period undertime amount to zero
                                    $undertime['second']['amount'] = 0;

                                    // Set second period undertime hours to zero
                                    $undertime['second']['undertime'] = 0;
                                }
                            } else {
                                /** alter paid_hours into hours given on travel order memo */
                                if ($payroll_info[$date]['travel_order']['result'] == true) {
                                    $paid_hours_breakdown = json_decode($payroll_info[$date]['travel_order']['data']['paid_hours_breakdown'], true);

                                    $payroll_info[$date]['daily_pay_info']['paid_hours'] = intval($paid_hours_breakdown[0]['hours']);
                                } else {
                                    // Subtract second period working hours from paid hours in payroll info
                                    $payroll_info[$date]['daily_pay_info']['paid_hours'] -= $second_period_working_hours;
                                }
                            }
                        }
                    }
                }

                /**
                 * Bind additional information to daily pay info in payroll info
                 *
                 * Daily Rate = Daily Rate from Service Record
                 * Hourly Rate = Hourly Rate from Service Record
                 * Total Working Hours
                 * First Period Working Hours
                 * Second Period Working Hours
                 * Absent Amount = Absence amount from first period + Absence amount from second period
                 * Night Differential Hours = Night Differential hours from first period + Night Differential hours from second period
                 * Night Differential Amount = Night Differential amount from first period + Night Differential amount from second period
                 * Undertime Minutes = Total Undertime minutes
                 * Undertime Amount = Total Undertime amount
                 * Overbreak Minutes = Overbreak minutes from period breaks
                 * Overbreak Amount = Total amount from period breaks
                 * Late Minutes = Total late minutes
                 * Late Amount = Total lates amount
                 * Has Late Penalty = Flag for late penalty
                 * Leave Credits = Total leave period credits
                 * Missing Logs = Flag for missing first/second set in DTR
                 * Schedule = JSON-encoded employee schedule information
                 */
                $payroll_info[$date]['daily_pay_info']['daily_rate'] = $service['service_record']['rate']['dRate'];
                $payroll_info[$date]['daily_pay_info']['hourly_rate'] = $service['service_record']['rate']['hRate'];
                $payroll_info[$date]['daily_pay_info']['total_working_hours'] = $total_working_hours;
                $payroll_info[$date]['daily_pay_info']['first_period_hours'] = $first_period_working_hours;
                $payroll_info[$date]['daily_pay_info']['second_period_hours'] = $second_period_working_hours;
                $payroll_info[$date]['daily_pay_info']['absent_amount'] = $absence['first']['amount'] + $absence['second']['amount'];
                $payroll_info[$date]['daily_pay_info']['night_differential_hours'] = $night_diff['first']['night_diff'] + $night_diff['second']['night_diff'];
                $payroll_info[$date]['daily_pay_info']['night_differential_amount'] = $night_diff['first']['amount'] + $night_diff['second']['amount'];
                $payroll_info[$date]['daily_pay_info']['undertime_minutes'] = intval($undertime['total_undertime']);
                $payroll_info[$date]['daily_pay_info']['undertime_amount'] = $undertime['total_amount'];
                $payroll_info[$date]['daily_pay_info']['overbreak_minutes'] = $period_breaks['overbreak'];
                $payroll_info[$date]['daily_pay_info']['overbreak_amount'] = $period_breaks['total_amount'];
                $payroll_info[$date]['daily_pay_info']['late_minutes'] = $lates['total_lates'];
                $payroll_info[$date]['daily_pay_info']['late_amount'] = $lates['total_amount'];
                $payroll_info[$date]['daily_pay_info']['has_late_penalty'] = $lates['penalty'] ? 1 : 0;
                $payroll_info[$date]['daily_pay_info']['leave_credits'] = $total_period_credits;
                $payroll_info[$date]['daily_pay_info']['missing_log'] = $log_sets["logs"]["dtr"]["has_missing_in_first_set"] || $log_sets["logs"]["dtr"]["has_missing_in_second_set"] ? 1 : 0;
                $payroll_info[$date]['daily_pay_info']['schedule'] = json_encode($emp_schedule);
                $payroll_info[$date]['daily_pay_info']['is_rest_day'] = 0;

                /**
                 * Check Leaves
                 *
                 * Possible Instances:
                 * - Employee field a leave for first period, but did not for second period
                 * - Employee did not file a leave for first period, but filed a leave for second period
                 * - Employee filed a leave for both first and second period
                 */

                // Check if employee filed a leave for first period and did not file a leave for second period
                if ($has_leave_in_first_period && !$has_leave_in_second_period) {
                    // Set flag for leave in daily pay info to 1 in payroll info
                    $payroll_info[$date]['daily_pay_info']['has_leave'] = 1;
                }

                // Check if employee did not file a leave for first period and filed a leave for second period
                if (!$has_leave_in_first_period && $has_leave_in_second_period) {
                    // Set flag for leave in daily pay info to 2 in payroll info
                    $payroll_info[$date]['daily_pay_info']['has_leave'] = 2;
                }

                // Check if employee filed a leave for first and second period
                if ($has_leave_in_first_period && $has_leave_in_second_period) {
                    $payroll_info[$date]['daily_pay_info']['has_leave'] = 3;
                }

                $payroll_info[$date]['daily_pay_info']['absent_amount'] = $absence['first']['amount'] + $absence['second']['amount'];

                /**
                 * Check Absences
                 *
                 * Possible Instances:
                 * - Employee is absent on first period, but is present on second period
                 * - Employee is present on first period, but is absent on second period
                 * - Employee is absent on first and second period
                 */

                /** FOR SINGLE PERIOD EMPLOYEE */
                if ($emp_schedule['period'] == 0) {
                    // EXCLUSIVELY FOR PIXEL8. FAIRNESS FOR EMPLOYEES WHO HAD A SINGLE-PERIOD SCHEDULE.
                    if ($absence['first']['absent_hour'] > 0) {
                        $payroll_info[$date]['daily_pay_info']['absent_period'] = 1;
                        if (
                            isset($payroll_info[$date]['missing_log']) ||
                            ($first_period_leave['result'] && $first_period_leave['leave']['is_paid']) ||
                            ($second_period_leave['result'] && $second_period_leave['leave']['is_paid'])
                        ) {
                            // HAS MISSING LOGS
                            $payroll_info[$date]['daily_pay_info']['first_period_hours'] = 4;
                            $payroll_info[$date]['daily_pay_info']['paid_hours'] = 4;
                        } else {
                            // WHOLE DAY ABSENT
                            $payroll_info[$date]['daily_pay_info']['absent_period'] = 1;
                            $payroll_info[$date]['daily_pay_info']['first_period_hours'] = 0;
                            $payroll_info[$date]['daily_pay_info']['paid_hours'] = 0;
                        }
                    }
                } else {
                    /** FOR TWO PERIOD EMPLOYEE */

                    // Check if employee is absent for first period and present for second period
                    if ($absence['first']['amount'] > 0 && $absence['second']['amount'] == 0) {
                        // Set flag for absent period in daily pay info to 1 in payroll info
                        $payroll_info[$date]['daily_pay_info']['absent_period'] = 1;
                    }

                    // Check if employee is present for first period and absent for second period
                    if ($absence['first']['amount'] == 0 && $absence['second']['amount'] > 0) {
                        // Set flag for absent period in daily pay info to 2 in payroll info
                        $payroll_info[$date]['daily_pay_info']['absent_period'] = 2;
                    }

                    // Check if employee is absent for first and second period
                    if ($absence['first']['amount'] > 0 && $absence['second']['amount'] > 0) {
                        // Set flag for absent period in daily pay info to 3 in payroll info
                        $payroll_info[$date]['daily_pay_info']['absent_period'] = 3;
                    }
                }


                //add to allowance computation (?)
                if ($payroll_info[$date]['daily_pay_info']['has_leave'] > 0) {
                    // ???
                }

                // Check if total working hours is greater than 0
                if ($total_working_hours > 0) {
                    // Add total period credits to total leave credits used in working hours
                    $working_hours[$total_working_hours]['total_leave_credits_used'] += $total_period_credits;

                    /**
                     * Total Paid Days = Total Paid Days + (Paid Hours / Total Working Hours)
                     */
                    $working_hours[$total_working_hours]['total_paid_days'] += ($payroll_info[$date]['daily_pay_info']['paid_hours'] / $total_working_hours);
                }

                /**
                 * Multiple Conditions:
                 * - Check if employee schedule is two periods
                 * - Employee did not time in for first period and second period
                 *  OR
                 * - Check if employee schedule is one period only
                 * - Employee did not time in for first period
                 *
                 * If conditions above are not satisfied:
                 * - Check if there is a holiday on current date
                 */
                // if (($emp_schedule['period'] == '1'
                //         && (!$first_time_in_present && !$second_time_in_present))
                //     || (($emp_schedule['period'] == '0')
                //         && (!$first_time_in_present))
                // ) {
                // Check if there is/are holiday(s) in current date
                if (count($holiday_in_date)) {
                    // Check if the configuration allows paid holidays
                    if (!array_key_exists('config', $holiday_in_date['0'])) {
                        /**
                         * check prev dtr (?)
                         * check if required to work (?)
                         */

                        // Check if employee is required to work based on his schedule
                        if (isset($emp_schedule['required_work']) && $emp_schedule['required_work'] == 1) {
                            // Set paid hours in daily pay info to zero in payroll info
                            $payroll_info[$date]['daily_pay_info']['paid_hours'] = 0;
                        } else {
                            // FOR SPECIAL NON WORKING HOLIDAY
                            if ($holiday_in_date[0]['type'] == 0) {
                                // Set is holiday flag to 0 in special holiday if absent and required to work
                                if ($payroll_info[$date]['daily_pay_info']['paid_hours'] > 0 && $cutoff_holidays[$date][0]['required_work'] == 1) {
                                    $has_holiday_pay = true;
                                } else {
                                    $payroll_info[$date]['daily_pay_info']['absent_period'] = 0;
                                    $payroll_info[$date]['daily_pay_info']['absent_amount'] = 0;
                                    $has_holiday_pay = false;
                                }

                                $payroll_info[$date]['daily_pay_info']['is_holiday'] = $has_holiday_pay ? 1 : 0;
                                $payroll_info[$date]['daily_pay_info']['holiday_type'] = 0;
                            } else {
                                // FOR REGULAR HOLIDAY
                                $payroll_info[$date]['daily_pay_info']['is_holiday'] = 1;
                                $payroll_info[$date]['daily_pay_info']['holiday_type'] = 1;

                                // GET THE WORK DAYS
                                $this->db->where('ss.start_date', $start_date, '<=');
                                $this->db->where('ss.end_date', $end_date, '>=');
                                $this->db->where('ss.employee_id', $employee_id);
                                $this->db->join('emp_schedules s', 'ss.id=s.schedule_setup_id');
                                $schedule_days = $this->db->get('emp_schedule_setup ss', null, 's.day');

                                $temp_prev_day = new DateTime($date);
                                $temp_prev_day_string = $temp_prev_day->format('Y-m-d');

                                // Check if employee has no DTR entry on previous date
                                if (!empty($schedule_days) && $schedule_days[0]['day'] != 0) {
                                    do {
                                        // GO TO PREV DAY IF REST DAY
                                        do {
                                            $temp_prev_day->modify('-1 day');
                                            $temp_prev_day_string = $temp_prev_day->format('Y-m-d');
                                            $temp_prev_day_of_week = $temp_prev_day->format('w') == 0 ? 7 : $temp_prev_day->format('w');
                                        } while (!in_array(intval($temp_prev_day_of_week), array_column($schedule_days, 'day')));
                                    } while (
                                        /**
                                         * LOOP AGAIN IF DAY IS HOLIDAY
                                         * LOOP WILL END IF PREV DAY IS REGULAR WORKING DAY
                                         */
                                        in_array($temp_prev_day_string, array_keys($cutoff_holidays))
                                    );
                                }

                                $this->db->where('employee_id', $employee_id);
                                $this->db->where('date', $temp_prev_day_string);
                                // CHECK IF NO DTR ON PREV WORKING DATE
                                if (!$this->db->has('emp_dtr')) {
                                    // CHECK IF PREV WORKING DAY IS ON LEAVE WITH PAY
                                    $this->db->where('employee_id', $employee_id);
                                    $this->db->where('start_date', $temp_prev_day_string, '<=');
                                    $this->db->where('end_date', $temp_prev_day_string, '>=');
                                    $this->db->where('is_paid', 1);
                                    $this->db->where('leave_status', 2);
                                    $leave_on_prev_day = $this->db->has('emp_leaves');

                                    // Loop all holidays in current date
                                    // foreach ($holiday_in_date as $holiday) {
                                    // Bind total working hours to paid hours in payroll info
                                    $payroll_info[$date]['daily_pay_info']['paid_hours'] = $total_working_hours;

                                    // Set first time in based on employee's scheduled first period time in
                                    $first_time_in = $emp_schedule['logins']['first_in'];

                                    // Set last time out based on employee's scheduled time out
                                    $last_time_out = $emp_schedule['logins']['first_out'];

                                    // Check if employee schedule has two periods
                                    if ($emp_schedule['period'] == '1') {
                                        // Set last time out to be based on employee's scheduled second period time out instead
                                        $last_time_out = $emp_schedule['logins']['second_out'];
                                    }

                                    // Push information to employee holidays
                                    array_push(
                                        $employee_holidays,
                                        array(
                                            'employee_id' => $employee_id,
                                            'date_applied' => $date,
                                            'start_date_time' => $first_time_in,
                                            'end_date_time' => $last_time_out,
                                            'honored_hours' => $total_working_hours,
                                            'pay_type' => 0,
                                            'additional_pay_type' => 1
                                        )
                                    );

                                    /**
                                     * Total Paid Days = Total Paid Days + (Paid Hours / Total Working Hours)
                                     */
                                    $working_hours[$total_working_hours]['total_paid_days'] += ($payroll_info[$date]['daily_pay_info']['paid_hours'] / $total_working_hours);

                                    $payroll_info[$date]['daily_pay_info']['is_holiday'] = $leave_on_prev_day ? 1 : 0;
                                    // }
                                }
                            }
                        }
                    } else {
                        // Set is holiday flag to 1 in payroll info
                        $payroll_info[$date]['daily_pay_info']['is_holiday'] = 1;
                    }
                } else {
                    $payroll_info[$date]['daily_pay_info']['is_holiday'] = 0;
                }
                // }
                // elseif (count($holiday_in_date)) {
                //     // Set is holiday flag to 1 in payroll info
                //     $payroll_info[$date]['daily_pay_info']['is_holiday'] = 1;
                // }

                // Add total lates amount to total lates
                $total_lates += $lates['total_amount'];

                // Add first and period undertime amounts to total undertime amount
                $total_undertime += $undertime['first']['amount'] + $undertime['second']['amount'];

                // Add first and second period overbreak amounts to total overbreak amount
                // $total_overbreak += $overbreak['first']['amount'] + $overbreak['second']['amount'];

                // Check if employee is absent for both periods
                if ($payroll_info[$date]['daily_pay_info']['absent_period'] === 3) {
                    // Check if employee filed a leave for first period and did not for second period
                    if ($has_leave_in_first_period && !$has_leave_in_second_period) {
                        // Add absence amount from second period to total absences amount (why add zero?)
                        $total_absences += 0 + $absence['second']['amount'];
                    }

                    // Check if employee did not file a leave for first period and filed for second period
                    if (!$has_leave_in_first_period && $has_leave_in_second_period) {
                        // Add absence amount from first period to total absences amount (again, why add zero?)
                        $total_absences += $absence['first']['amount'] + 0;
                    }

                    // Check if employee did not file a leave for first and second period
                    if (!$has_leave_in_first_period && !$has_leave_in_second_period) {
                        // Add absence amount from first and second period to total absences amount
                        $total_absences += $absence['first']['amount'] + $absence['second']['amount'];
                    }
                }

                // Check if employee is absent for second period only
                if ($payroll_info[$date]['daily_pay_info']['absent_period'] === 2) {
                    // Check if employee filed a leave
                    if ($has_leave_in_second_period) {
                        // Add absences amount to 0 (what. why.)
                        $total_absences += 0;
                    } else {
                        // Add second period absence amount to total absences amount
                        $total_absences += $absence['second']['amount'];
                    }
                }

                // Check if employee is absent for first period only
                if ($payroll_info[$date]['daily_pay_info']['absent_period'] === 1) {
                    // Check if employee filed a leave
                    if ($has_leave_in_first_period) {
                        // Add absences amount to 0 (what. why.)
                        $total_absences += 0;
                    } else {
                        // Add first period absence amount to total absences amount
                        $total_absences += $absence['first']['amount'];
                    }
                }

                // ????????
                // $total_absences += $absence['first']['amount'] + $absence['second']['amount'];

                // Add service record daily rate to total basic salary
                $total_basic_salary += $service['service_record']["rate"]["dRate"];

                /**
                 * Total Wage = Total Wage + (Paid Hours from Daily Pay Info * Hourly Rate from Service Record)
                 */
                $total_wage += $payroll_info[$date]['daily_pay_info']['paid_hours'] * $service['service_record']["rate"]["hRate"];

                // Add first and second period night differential amounts to total night differential
                $total_night_differential += $night_diff['first']['amount'] + $night_diff['second']['amount'];
            } else {

                // //get the previous date
                // $previous_date = new DateTime($date);
                // $previous_date->modify('-1 day');
                // $previous_date = $previous_date->format('Y-m-d');

                // $emp_sched_memo = $branch_schedule_helper->findEmployeeSchedule($employee_id, $previous_date, $employee_schedules);

                // // Bind employee schedule to variable
                // $emp_schedule = $emp_sched_memo['schedule'];

                // //get the next day

                // $emp_dtr = $employee_dtr[$employee_id];

                // $log_sets_previous = $dtr_getter->getEmployeeDtrLogs($previous_date, $date, $emp_schedule, $employee_dtr[$employee_id], $emp_schedule_next, true);

                // foreach($emp_dtr as $key => $value){
                //     foreach($log_sets_previous['used_logs'] as $lsp){
                //         if($lsp['date_time'] == $value['date_time']){
                //             unset($emp_dtr[$key]);
                //         }
                //     }
                // }


                /**
                 * no work/no Sched/rest day
                 * if employee is monthly rater - paid
                 * else if daily rater - no work no pay
                 */
                $worked_hours_and_night_diff_hours = [];
                // Get employee Worked Hours and Night Diff Hours Based on DTR Logs
                if (count($employee_dtr)) {
                    if (isset($employee_dtr[$employee_id])) {
                        $worked_hours_and_night_diff_hours = $dtr_getter->getEmployeeWorkedHoursNightHoursRestday($employee_id, $date, $employee_dtr[$employee_id]);
                    }
                }

                // Check if has logs on rest day
                if (is_array($worked_hours_and_night_diff_hours) && count($worked_hours_and_night_diff_hours) && isset($worked_hours_and_night_diff_hours['worked_hours']) && $worked_hours_and_night_diff_hours['worked_hours'] > 0) {
                    $payroll_config = $this->configuration['payroll_config'][$branch_id];

                    // Check if had an OT Hours
                    $ot_hours = $worked_hours_and_night_diff_hours['worked_hours'] - $total_working_hours;

                    // Set Paid hours of Rest Day
                    $rest_day_paid_hours = $ot_hours > 0 ? $total_working_hours : $worked_hours_and_night_diff_hours['worked_hours'];

                    // Bind daily rate from service record to daily pay info in payroll info
                    $payroll_info[$date]['daily_pay_info']['daily_rate'] = $service['service_record']['rate']['dRate'];

                    // Bind hourly rate from service record to daily pay info in payroll info
                    $payroll_info[$date]['daily_pay_info']['hourly_rate'] = $service['service_record']['rate']['hRate'];

                    // Bind total working hours to daily pay info in payroll info
                    $payroll_info[$date]['daily_pay_info']['total_working_hours'] = $total_working_hours;

                    // Bind total working hours to daily pay info paid hours in payroll info
                    $payroll_info[$date]['daily_pay_info']['paid_hours'] = $rest_day_paid_hours;

                    // Add indicator for rest day
                    $payroll_info[$date]['daily_pay_info']['is_rest_day'] = 1;

                    // set identifier if found and holiday on date and what holiday type
                    $found = false;
                    $holiday_type = null;

                    // DETERMINE IF HOLIDAY
                    if (isset($payroll_info[$date]['holiday_in_date']) && count($payroll_info[$date]['holiday_in_date'])) {
                        foreach ($payroll_info[$date]['holiday_in_date'] as $holiday_date) {

                            // check if had an night diff hours
                            if (isset($worked_hours_and_night_diff_hours['night_differential_hours'])) {
                                // Check if night diff hours is more than 0
                                if ($worked_hours_and_night_diff_hours['night_differential_hours'] > 0) {
                                    // set total night diff hours
                                    $total_nd_hours = $worked_hours_and_night_diff_hours['night_differential_hours'];

                                    // set night diff, rest date and holiday rate
                                    $night_diff_rate_rd = (($payroll_config['rest_day_night_diff_percentage']) / 100);
                                    $rest_day_rate = (($payroll_config['rest_day_percentage'] + 100) / 100);
                                    $holiday_rate = $holiday_date['type'] == 0 ? (($payroll_config['rest_day_special_holiday_percentage'] + 100) / 100) : (($payroll_config["regular_holiday_percentage"] + 100) / 100);

                                    // compute night diff pay
                                    // set separate computation for regular and special non working holiday
                                    if ($holiday_date['type'] == 1) {
                                        $total_nd_amount = $service['service_record']["rate"]["hRate"] * $holiday_rate * $rest_day_rate * $night_diff_rate_rd * $rest_day_paid_hours;
                                    } else {
                                        $total_nd_amount = $service['service_record']["rate"]["hRate"] * $holiday_rate * $night_diff_rate_rd * $rest_day_paid_hours;
                                    }

                                    $payroll_info[$date]['daily_pay_info']['night_differential_amount'] = round($total_nd_amount, 2);

                                    $payroll_info[$date]['daily_pay_info']['night_differential_hours'] = $worked_hours_and_night_diff_hours['night_differential_hours'];
                                }
                            } else {
                                // set night diff hours
                                $payroll_info[$date]['daily_pay_info']['night_differential_amount'] = 0;

                                // set night diff hours
                                $payroll_info[$date]['daily_pay_info']['night_differential_hours'] = 0;
                            }

                            if ($holiday_date['date'] == $date) {
                                $found = true;
                                $holiday_type = $holiday_date['type'];
                                break;
                            }
                        }
                    }

                    // APPLY REST DAY PAY ON REGULAR DAYS ONLY
                    if (!$found) {
                        // Add Rest Day Pay
                        $rate_percentage = $payroll_config['rest_day_percentage'] / 100;

                        // Compute Rest Day Pay
                        if ($rest_day_paid_hours >= 8) {
                            $payroll_info[$date]['daily_pay_info']['rest_day_paid'] = $service['service_record']['rate']['dRate'] * $rate_percentage;
                        } else {
                            $payroll_info[$date]['daily_pay_info']['rest_day_paid'] = $service['service_record']['rate']['hRate'] * $rate_percentage * $rest_day_paid_hours;
                        }

                        // check if had an night diff hours
                        if (isset($worked_hours_and_night_diff_hours['night_differential_hours'])) {
                            // Check if night diff hours is more than 0
                            if ($worked_hours_and_night_diff_hours['night_differential_hours'] > 0) {
                                // set total night diff hours
                                $total_nd_hours = $worked_hours_and_night_diff_hours['night_differential_hours'];

                                // set night diff rate
                                $night_diff_rate_rd = (($payroll_config['rest_day_night_diff_percentage']) / 100);

                                // compute night diff pay
                                $total_nd_amount = (($total_nd_hours * $service['service_record']["rate"]["hRate"] * $night_diff_rate_rd) - ($service['service_record']["rate"]["hRate"] * $total_nd_hours));
                                $payroll_info[$date]['daily_pay_info']['night_differential_amount'] = round($total_nd_amount, 2);

                                // set night diff hours
                                $payroll_info[$date]['daily_pay_info']['night_differential_hours'] = $worked_hours_and_night_diff_hours['night_differential_hours'];
                            }
                        }
                    } else {
                        // Add indicator for rest day
                        $payroll_info[$date]['daily_pay_info']['holiday_type'] = $holiday_type;
                    }

                    // Check Overtime and Process the Rest Day OT
                    if ($ot_hours > 0) {
                        // set ot nd hours
                        $ot_nd_hours = isset($worked_hours_and_night_diff_hours['ot_night_diff_hours']) ? $worked_hours_and_night_diff_hours['ot_night_diff_hours'] : 0;

                        // Bind holiday information to variable if there is one based on date
                        $holidays = isset($payroll_info[$date]['holiday_in_date']) && count($payroll_info[$date]['holiday_in_date']) ? $payroll_info[$date]['holiday_in_date'] : array();

                        // Start Processing OT
                        $ot_process = $this->processPayrollOvertimeRestDay($date, $employee_id, $ot_hours, $payroll_config, $service['service_record']['rate'], $payroll_id, $holidays, $ot_nd_hours);
                    } else {
                        // send log debug, it logs the result of the process of ot
                        $this->Logger->logDebug("No OT Hours from DTR Logs: " . __LINE__, $ot_hours);
                    }

                    /**
                     * Total Wage = Total Wage + (Paid Hours from Daily Pay Info * Hourly Rate from Service Record)
                     */
                    $total_wage += $payroll_info[$date]['daily_pay_info']['paid_hours'] * $service['service_record']["rate"]["hRate"];
                } else {
                    // Check if employee rest day is paid
                    if ($rest_day_paid) {
                        // Bind daily rate from service record to daily pay info in payroll info
                        $payroll_info[$date]['daily_pay_info']['daily_rate'] = $service['service_record']['rate']['dRate'];

                        // Bind hourly rate from service record to daily pay info in payroll info
                        $payroll_info[$date]['daily_pay_info']['hourly_rate'] = $service['service_record']['rate']['hRate'];

                        // Bind total working hours to daily pay info in payroll info
                        $payroll_info[$date]['daily_pay_info']['total_working_hours'] = $total_working_hours;

                        // Bind first period working hours to daily pay info in payroll info
                        $payroll_info[$date]['daily_pay_info']['first_period_hours'] = $first_period_working_hours;

                        // Bind second period working hours to daily pay info in payroll info
                        $payroll_info[$date]['daily_pay_info']['second_period_hours'] = $second_period_working_hours;

                        // Bind total working hours to daily pay info paid hours in payroll info
                        $payroll_info[$date]['daily_pay_info']['paid_hours'] = $total_working_hours;

                        // Check if total working hours is greater than 0
                        if ($total_working_hours > 0) {
                            /**
                             * Total Paid Days = Total Paid Days + (Paid Hours / Total Working Hours)
                             */
                            $working_hours[$total_working_hours]['total_paid_days'] += ($payroll_info[$date]['daily_pay_info']['paid_hours'] / $total_working_hours);
                        }

                        // Add daily rate to total basic salary
                        $total_basic_salary += $service['service_record']["rate"]["dRate"];

                        /**
                         * Total Wage = Total Wage + (Paid Hours from Daily Pay Info * Hourly Rate from Service Record)
                         */
                        $total_wage += $payroll_info[$date]['daily_pay_info']['paid_hours'] * $service['service_record']["rate"]["hRate"];
                    }
                }

                // Check if total working hours is greater than 0
                if ($total_working_hours > 0) {
                    /**
                     * Total Paid Days = Total Paid Days + (Paid Hours / Total Working Hours)
                     */
                    $working_hours[$total_working_hours]['total_paid_days'] += ($payroll_info[$date]['daily_pay_info']['paid_hours'] / $total_working_hours);
                }
            }

            // Set first date to false
            $first_date = false;

            /** if holiday, update is_holiday value to 1 */
            count($holiday_in_date) && $payroll_info[$date]['daily_pay_info']['is_holiday'] = 1;

            // Push daily pay info to date information
            array_push($date_information, $payroll_info[$date]['daily_pay_info']);
        }

        // Congrats, you made it
        return array(
            'date_information' => $date_information,
            'payroll_info' => $payroll_info,
            'leave' => $leave,
            'dtr_on_prev_date' => $dtr_on_prev_date,
            'employee_leaves' => $employee_leaves,
            'employee_holidays' => $employee_holidays,
            'working_hours' => $working_hours,
            'latest_primary_branch_department' => $latest_primary_branch_department,
            'total_lates' => $total_lates,
            'total_undertime' => $total_undertime,
            'total_absences' => $total_absences,
            'total_basic_salary' => $total_basic_salary,
            'total_wage' => $total_wage,
            'total_night_differential' => $total_night_differential,
            'has_missing_log' => $has_missing_log,
            'has_suspension' => $has_suspension
        );
    }

    /**
     * Validate Allowance
     *
     * @param string $branch_id
     * @param array $lates
     * @param array $lates
     * @return array
     * @throws \Exception
     */
    public function validateAllowance($service, $total_working_hours, $first_period_working_hours, $second_period_working_hours, $branch_id, $lates, $undertime, $absence)
    {
        $monthly_term_deduction = 0;
        $daily_term_deduction = 0;
        $configuration = $this->configuration['payroll_config'][$branch_id];
        if (($configuration['allowance_include_period_absence_for_monthly'] == 1 && $service['service_record']['rate_type'] == 0) || ($configuration['allowance_include_period_absence_for_non_monthly'] == 1 && ($service['service_record']['rate_type'] == 1 && $service['service_record']['rate_type'] == 2))) {
            //absence
            if ($absence['first']['amount'] > 0 && $absence['second']['amount'] == 0) {
                $period = $first_period_working_hours / $total_working_hours;
                $monthly_term_deduction = $period;
                $daily_term_deduction = $period;
            }
            if ($absence['first']['amount'] == 0 && $absence['second']['amount'] > 0) {
                $period = $second_period_working_hours / $total_working_hours;
                $monthly_term_deduction = $period;
                $daily_term_deduction = $period;
            }
            if ($absence['first']['amount'] > 0 && $absence['second']['amount'] > 0) {
                $monthly_term_deduction = 1;
                $daily_term_deduction = 1;
            }
        }
        return array('monthly_term_deduction' => $monthly_term_deduction, 'daily_term_deduction' => $daily_term_deduction);
    }


    /**
     * The following will cater the function:
     * Overtime for Rest Day
     * Overtime for Rest Day at the same time Holiday
     * Overtime for Rest Day, Night Diff
     * Overtime for Rest Day, Night Diff and at the same time Holiday
     */
    public function processPayrollOvertimeRestDay($date_for_check, $employee_id, $ot_hours, $config, $sr_rate, $payroll_id, $holidays, $ot_nd_hours = 0)
    {
        // Fetch Overtime of the employee
        $this->db->where('pay_type', '0');
        $this->db->where("CAST(start_date_time as DATE )", $date_for_check);
        $this->db->where("employee_id", $employee_id);
        $this->db->where("payroll_id", '0');
        $this->db->orWhere("payroll_id", null);
        $overtime_data = $this->db->get('emp_otholiday', null);

        $rest_day_overtime_insert = array();

        //check if employee has an overtime
        if (is_array($overtime_data) && count($overtime_data)) {
            foreach ($overtime_data as $overtime) {
                // check if ot hours base on dtr is equal to honored_hours of overtime
                if ($overtime['honored_hours'] == $ot_hours) {


                    // // Set Rest Day rate based on payroll config
                    // $rest_day_rate = ($config["rest_day_percentage"]+100)/100;

                    // // set night diff rate on rest day
                    // $night_diff_rate_rd = (($config["rest_day_night_diff_percentage"]+100)/100);

                    /**
                     *
                     *      SET Rate of Rest Day Holiday Rate, Rest Day Overtime Night Diff
                     *              TO 1
                     *
                     */
                    $rest_day_rate = $night_diff_percentage_rd = 1;

                    // Set overtime rate for the rest day based on payroll config
                    $overtime_rest_day_rate = ($config['rest_day_overtime_percentage'] + 100) / 100;

                    $regular_rate = 0;


                    // Set hours of ot regular rest day
                    $regular_hours_rd = $ot_hours;

                    //set identifier to initiate computation on ot nd
                    $to_compute_ot_nd = false;

                    // check if have an ot on nd and if ot hours is greater that ot nd hours (this usually prepare for deduction)
                    if ($ot_nd_hours > 0) {
                        //adjust ot hours, deduct ot nd hours to ot hours because ot nd have an separate computation
                        $to_compute_ot_nd = true;
                        $night_diff_percentage_rd = (($config["rest_day_night_diff_percentage"] + 100) / 100);
                    }

                    //check if ot on rest day is holiday
                    if (is_array($holidays) && count($holidays)) {
                        /**
                         *
                         *      If Overtime on Rest Day Does land on Holiday
                         *
                         */
                        foreach ($holidays as $holiday_value) {

                            // holiday_type 0 is special holiday , holiday_type 1 is regular
                            if ($holiday_value['type'] == 0) {
                                /***
                                 *
                                 *  Compute for restday ot on special holiday
                                 *
                                 */
                                $holiday_rate = ($config["rest_day_special_holiday_percentage"] + 100) / 100;

                                //compute for Ot amount
                                $ot_amount = $overtime_rest_day_rate * $ot_hours * $sr_rate['hRate'];

                                if ($to_compute_ot_nd) {

                                    //compute for night diff amount
                                    $night_diff_hours_amount_rd = ($overtime_rest_day_rate * $ot_nd_hours * $night_diff_percentage_rd * $sr_rate['hRate']) - ($overtime_rest_day_rate * $ot_nd_hours * $sr_rate['hRate']);

                                    //compute for holiday amount on rest day
                                    $holiday_hours_amount_rd = (($ot_amount + $night_diff_hours_amount_rd) * $holiday_rate) - ($ot_amount + $night_diff_hours_amount_rd);

                                    //set rest_day_rate and rest day amount to zero
                                    $rest_day_rate = $rest_day_ot_amount = 0;
                                } else {
                                    //compute for holiday amount on rest day
                                    $holiday_hours_amount_rd = ($ot_amount * $holiday_rate) - $ot_amount;

                                    //set rest_day_rate and rest day amount to zero
                                    $rest_day_rate = $rest_day_ot_amount = $night_diff_hours_amount_rd = $night_diff_percentage_rd = 0;
                                }
                            } else {
                                /***
                                 *
                                 *  Compute for restday ot on Regular holiday
                                 *
                                 */
                                $holiday_rate = ($config["regular_holiday_percentage"] + 100) / 100;

                                //compute for Ot amount
                                $ot_amount = $overtime_rest_day_rate * $ot_hours * $sr_rate['hRate'];

                                $rest_day_rate = ($config["rest_day_percentage"] + 100) / 100;


                                if ($to_compute_ot_nd) {

                                    //compute for night diff amount
                                    $night_diff_hours_amount_rd = ($overtime_rest_day_rate * $ot_nd_hours * $night_diff_percentage_rd * $sr_rate['hRate']) - ($overtime_rest_day_rate * $ot_nd_hours * $sr_rate['hRate']);

                                    //compute for holiday amount on rest day
                                    $holiday_hours_amount_rd = (($ot_amount + $night_diff_hours_amount_rd) * $holiday_rate) - ($ot_amount + $night_diff_hours_amount_rd);
                                } else {

                                    //set night diff amount and rate to zero
                                    $night_diff_hours_amount_rd = $night_diff_percentage_rd = 0;
                                }

                                //compute for rest day amount
                                $rest_day_ot_amount = (($ot_amount + $night_diff_hours_amount_rd) * $rest_day_rate) - ($ot_amount + $night_diff_hours_amount_rd);

                                //compute for holiday amount
                                $holiday_hours_amount_rd = (($ot_amount + $night_diff_hours_amount_rd + $rest_day_ot_amount) * $holiday_rate) - ($ot_amount + $night_diff_hours_amount_rd + $rest_day_ot_amount);
                            }
                        }
                    } else {
                        /**
                         *
                         *      If Overtime on Rest Day Does NOT land on Holiday
                         *
                         */

                        // Set Rest Day rate based on payroll config
                        $rest_day_rate = ($config["rest_day_percentage"] + 100) / 100;

                        //set holiday rate and holiday amount as zero
                        $holiday_rate = $holiday_hours_amount_rd = 0;



                        /**
                         * Compute for "Overtime" and "Night Diff on Overtime" on  Rest Day
                         */

                        //compute for  OT amount
                        $ot_amount =  $ot_hours * $sr_rate['hRate'];

                        if ($to_compute_ot_nd) {

                            //compute for night diff amount
                            $night_diff_hours_amount_rd = (($overtime_rest_day_rate * $night_diff_percentage_rd * $ot_nd_hours * $sr_rate['hRate']) - ($overtime_rest_day_rate * $ot_nd_hours * $sr_rate['hRate']));
                        } else {
                            //set night diff percentage and night diff amount to zero
                            $night_diff_percentage_rd = $night_diff_hours_amount_rd = 0;
                        }

                        //compute for rest day amount
                        $rest_day_ot_amount = (($ot_amount + $night_diff_hours_amount_rd) * $rest_day_rate) - ($ot_amount + $night_diff_hours_amount_rd);
                    }

                    // // check if the ot on rest day is holiday
                    // if (is_array($holidays) && count($holidays)) {
                    //     $holiday_hours_amount_rd = 0;
                    //     $total_rate_holiday = 0;
                    //     $night_diff_hours_amount_rd = 0;
                    //     $night_diff_hours_rd = 0;
                    //     $night_diff_percentage_rd = 0;
                    //     $holiday_rate_sph_rest_day = 0;
                    //     $regular_holiday_rate = 0;
                    //     foreach ($holidays as $holiday_value) {
                    //         // Check if what type of holiday,
                    //         //(the two types of holiday is special non working holiday and regular holiday)
                    //         if ($holiday_value['type'] == 0) {

                    //             // set rate for special non working holiday rate and rate of rest day
                    //             if ($holiday_rate_sph_rest_day == 0) {
                    //                 $holiday_rate_sph_rest_day = ($config["rest_day_special_holiday_percentage"]+100)/100;
                    //             } else {
                    //                 $holiday_rate_sph_rest_day *= ($config["rest_day_special_holiday_percentage"]+100)/100;
                    //             }

                    //             // check if the identifiers set to compute ot nd on special non working holiday
                    //             if ($to_compute_ot_nd) {
                    //                 // start process for computation of ot nd
                    //                 // compute the amount of ot nd on rest day
                    //                 $night_diff_hours_amount_rd += $sr_rate['hRate'] * $holiday_rate_sph_rest_day * $overtime_rest_day_rate *  $night_diff_rate_rd * $ot_nd_hours;

                    //                 // set ot nd hours and percentage
                    //                 $night_diff_hours_rd += $ot_nd_hours;
                    //                 $night_diff_percentage_rd += $holiday_rate_sph_rest_day * $overtime_rest_day_rate *  $night_diff_rate_rd;
                    //             }

                    //             // compute overtime amount for special non working holiday rest day
                    //             $holiday_hours_amount_rd += $sr_rate['hRate'] * $holiday_rate_sph_rest_day * $overtime_rest_day_rate * $regular_hours_rd;

                    //             //Set total ot rate for special non working holiday
                    //             $total_rate_holiday += $holiday_rate_sph_rest_day * $overtime_rest_day_rate;

                    //         } elseif ($holiday_value['type'] == 1) {
                    //             // set rate for regular holiday rate and rate of rest day
                    //             if ($regular_holiday_rate == 0) {
                    //                 $regular_holiday_rate = ($config["regular_holiday_percentage"]+100)/100;
                    //             } else {
                    //                 $regular_holiday_rate *= ($config["regular_holiday_percentage"]+100)/100;
                    //             }


                    //             // check if the identifiers set to compute ot nd on special non working holiday
                    //             if ($to_compute_ot_nd) {
                    //                 // start process for computation of ot nd
                    //                 // compute the amount of ot nd on rest day
                    //                 $night_diff_hours_amount_rd += $sr_rate['hRate'] *$regular_holiday_rate * $rest_day_rate * $overtime_rest_day_rate *  $night_diff_rate_rd * $ot_nd_hours;

                    //                 // set ot nd hours and percentage
                    //                 $night_diff_hours_rd += $ot_nd_hours;
                    //                 $night_diff_percentage_rd += $regular_holiday_rate * $rest_day_rate * $overtime_rest_day_rate *  $night_diff_rate_rd;
                    //             }

                    //             // compute overtime amount for regular holiday rest day
                    //             $holiday_hours_amount_rd += $sr_rate['hRate'] * $regular_holiday_rate * $rest_day_rate * $overtime_rest_day_rate * $regular_hours_rd;

                    //             //Set total ot rate for Regular holiday
                    //             $total_rate_holiday += $regular_holiday_rate * $rest_day_rate * $overtime_rest_day_rate;

                    //         }
                    //     }

                    //     // Set value of regular ot amount and hours to 0
                    //     $rest_day_ot_amount = 0;
                    //     $rest_day_ot_percentage  = 0;
                    // } else {

                    //     // check if the identifiers set to compute ot nd
                    //     if ($to_compute_ot_nd) {
                    //         // start process for computation of ot nd
                    //         // compute the amount of ot nd on rest day
                    //         $night_diff_hours_amount_rd = ($sr_rate['hRate'] * $overtime_rest_day_rate  *  $ot_nd_hours * $night_diff_rate_rd) - ($sr_rate['hRate'] * $overtime_rest_day_rate * $rest_day_rate *  $ot_nd_hours);

                    //         // set ot nd hours and percentage
                    //         $night_diff_hours_rd = $ot_nd_hours;
                    //         $night_diff_percentage_rd = $night_diff_rate_rd;
                    //     } else {
                    //         //set night diff amount, hours, percentage to 0
                    //         $night_diff_hours_amount_rd = 0;
                    //         $night_diff_hours_rd = 0;
                    //         $night_diff_percentage_rd = 0;
                    //     }

                    //     // compute overtime amount regular rest day
                    //     $rest_day_ot_amount = $sr_rate['hRate'] * $rest_day_rate * $overtime_rest_day_rate * $regular_hours_rd;
                    //     // Set Regular OT Percentage
                    //     $regular_percentage = $rest_day_rate * $overtime_rest_day_rate;

                    //     // Set value of ot holiday amount and hours to 0
                    //     $holiday_hours_amount_rd = 0;
                    //     $total_rate_holiday = 0;
                    // }


                    // Other overtime amount (will add computation soon)
                    $excess_amount_rd = 0;

                    // Other ot hours (will add computation soon)
                    $excess_hours_rd = 0;

                    //excess Rate
                    $excess_rate = 0;

                    //compute for total amount
                    $total_amount = $ot_amount + $rest_day_ot_amount + $night_diff_hours_amount_rd + $holiday_hours_amount_rd;


                    // // Compute total Amount of OT on Rest Day
                    // $total_amount = $rest_day_ot_amount + $holiday_hours_amount_rd + $night_diff_hours_amount_rd + $excess_amount_rd;

                    // Build Data or parameter to be insert in Payroll Overtime
                    $pr_ot_insert = array(
                        'payroll_id' => $payroll_id,
                        'date' => $date_for_check,
                        'overtime_type_id' => 0,

                        'regular_hours' => round($ot_hours, 2),
                        'regular_amount' => round($ot_amount, 2),
                        'regular_percentage' => $overtime_rest_day_rate,

                        'rest_day_overtime_amount' => round($rest_day_ot_amount, 2),
                        'rest_day_overtime_percentage' => $rest_day_rate,

                        'night_differential_hours' => $ot_nd_hours,
                        'night_differential_amount' =>  round($night_diff_hours_amount_rd, 2),
                        'night_differential_percentage' => $night_diff_percentage_rd,

                        'excess_hours' => $excess_amount_rd,
                        'excess_amount' => round($excess_hours_rd, 2),
                        'excess_percentage' => $excess_rate,

                        'holiday_ot_hours' => 0,
                        'holiday_ot_amount' => round($holiday_hours_amount_rd, 2),
                        'holiday_ot_percentage' => $holiday_rate,

                        'total_amount' => bcdiv($total_amount, 1, 2)
                    );

                    // Execute insert query
                    $pr_ot_result = $this->db->insert('pr_overtime', $pr_ot_insert);

                    // check if the query has error
                    if ($this->db->getLastErrno() !== 0) {
                        return array(
                            'result' => false,
                            'message' => $this->db->getLastError()
                        );
                    } else {
                        //Double checking if insertion is success
                        if ($pr_ot_result) {
                            // update payroll id in employee overtime record
                            $this->db->where('id', $overtime['id'])->update('emp_otholiday', array(
                                'payroll_id' => $payroll_id,
                            ));

                            if ($this->db->getLastErrno() !== 0) {
                                return array(
                                    'result' => false,
                                    'message' => 'Error to update the EMP OT Record, for full Details : ' . $this->db->getLastError()
                                );
                            } else {
                                // send response
                                return array(
                                    'result' => true,
                                    'message' => 'Success to insert OT on Payroll and Update EMP OT Record' . 'The employee ID was : ' . $overtime['employee_id'] . ' And the Payroll ID was : ' . $payroll_id
                                );
                            }
                        } else {
                            // send response
                            return array(
                                'result' => false,
                                'message' => 'Failed to insert OT on Payroll'
                            );
                        }
                    }
                } else {
                    // send response
                    return array(
                        'result' => false,
                        'message' => 'OT Hours of Logs and honored hours of OT Record doesnt match. OT hours: ' . $ot_hours . ' Honored Hours: ' . $overtime['honored_hours'] . '. Employee ID: ' . $employee_id
                    );
                }
            }

            // //insert multiple Rest day overtime
            // if(isset($rest_day_overtime_insert)){
            //     $ids = $this->db->insertMulti('pr_overtime', $rest_day_overtime_insert);
            // }
            // else{
            //     return array(
            //         'result' => false,
            //         'message' => 'No Rest Day Overtime Inserted'
            //     );
            // }


        } else {
            // send response
            return array(
                'result' => false,
                'message' => 'No Overtime Record of Employee ID: ' . $employee_id . ' on date of ' . $date_for_check
            );
        }
    }

    /**
     * Get all unposted Overtime within the cut off date
     *
     * @param string $employee_id
     * @param array $payroll_info
     * @return array
     * @throws \Exception
     */
    public function getPayrollOvertimeHolidays(
        $employee_id,
        $payroll_info,
        $employee_overtime_and_holidays,
        $dtr_getter,
        $branch_schedule_helper,
        $payroll_id,
        $branch_id,
        $payroll_config,
    ) {
        $ot_on_holidays = array();
        $overtime = array();
        $employee = array($employee_id);
        $total_ot_holidays = 0;
        $total_regular = 0;
        $ot_start_date = array();
        $ot_end_date = array();
        $rest_day = false;
        $check = $employee_overtime_and_holidays;

        if (!empty($employee_overtime_and_holidays) && isset($employee_overtime_and_holidays['data'])) {
            foreach ($employee_overtime_and_holidays['data'] as $overtime_info) {
                $date_start = date_format(new DateTime($overtime_info['start_date_time']), 'Y-m-d');
                if (isset($payroll_info[$date_start]) || $date_start !== null) {
                    if ($overtime_info['payroll_id'] === 0) {
                        $ot_start_value = $overtime_info['start_date_time'];
                        $ot_end_value = $overtime_info['end_date_time'];

                        $ot_start_date_time = new DateTime($ot_start_value);
                        $ot_start_date = $ot_start_date_time->format('Y-m-d');

                        $ot_end_date_time = new DateTime($ot_end_value);
                        $ot_end_date = $ot_end_date_time->format('Y-m-d');

                        $next_date = new DateTime($ot_start_date);
                        $next_date->modify('+1 day');
                        $next_day = $next_date->format('Y-m-d');
                        $last_date = new DateTime($ot_end_date);
                        $last_date->modify('+1 day');
                        $last_day = $next_date->format('Y-m-d');
                        $previous_date = new DateTime($ot_start_date);
                        $previous_date->modify('-1 day');
                        $previous_day = $previous_date->format('Y-m-d');
                        if (!isset($payroll_info[$ot_start_date])) {
                            //get holidays
                            $ot_holidays = $dtr_getter->getHolidays($ot_start_date, $ot_end_date, $employee_id, $branch_id);
                            $holidays = isset($ot_holidays[$ot_start_date]) ? $ot_holidays[$ot_start_date] : array();
                            //get employee and branch assignments
                            //use BranchScheduleHelper

                            //populate employee branch assignment
                            $employee_branches = $branch_schedule_helper->getBranchAsignments('employee_id', $employee, $ot_start_date, $ot_end_date);
                            //get employee schedules
                            $schedules = $branch_schedule_helper->getEmployeeSchedules($employee_branches['employees'], $ot_start_date, $ot_end_date);
                            //populate employee memos
                            $memos = $branch_schedule_helper->getEmployeeMemos($employee_branches['departments'], $employee_branches['employees'], $ot_start_date, $ot_end_date);
                            //populate employee schedules
                            $employee_schedules = $branch_schedule_helper->populateEmployeeSchedules($employee_branches['employees'], $schedules, $previous_day, $last_day, $employee_branches, $memos);
                            //get employee schedule
                            //get employee schedule and memo
                            $emp_sched_memo = $branch_schedule_helper->findEmployeeSchedule($employee_id, $ot_start_date, $employee_schedules);
                            $emp_schedule = $emp_sched_memo['schedule'];
                            //get employee dtr
                            $employee_dtr = $dtr_getter->getDtr($ot_start_date, $ot_end_date, $employee_branches['employees']);
                            // $rest_day = true;
                            if ($emp_schedule != "noSched") {
                                // $rest_day = false;
                                $total_working_hours = $emp_schedule['logins']['first_period_working_hours'] + $emp_schedule['logins']['second_period_working_hours'];
                            } else {
                                $total_working_hours = $employee_schedules[$employee_id]['rest_day_pay'];
                            }

                            // $dt = new DateTime($ot_start_date);

                            // get employee service records
                            // get employee service records
                            if (!isset($payroll_config['payroll_type']) || $payroll_config['payroll_type'] === 0) { // * PRIVATE
                                $employee_service_records = $branch_schedule_helper->getServiceRecord($employee_branches['employees'], $ot_start_date, $ot_end_date);
                                $service = $branch_schedule_helper->getEmployeeServiceRecordInfo($employee_service_records[$employee_id], $ot_start_date, $total_working_hours);
                            } else {
                                if ($payroll_config['payroll_type'] === 1) { // * GOVERNMENT
                                    $service = $branch_schedule_helper->getPlantillaRecord($employee_id, $ot_start_date);
                                }
                            }

                            $branch_department_info = $branch_schedule_helper->getEmployeeBranchDepartmentRecordInfo($employee_branches['branch_records'][$employee_id]['branches'], $ot_start_date);
                            $branch_id = $branch_department_info['result'] ? $branch_department_info['branch_department']['branch_id'] : 0; // Cannot get Value of the branch_id
                            $service["service_record"]["rate"] = $this->rateComputationsforBasicsalary($branch_id, $service["service_record"]['salary'], $service["service_record"]['rate_type'], $total_working_hours);
                            $rate = $service["service_record"]["rate"];
                        } else {
                            // Stop loop if rest day
                            // if ($payroll_info[$ot_start_date]['is_rest_day'] == 1) {
                            //     continue;
                            // }

                            // Start Here, move these variables outsie !isset function
                            $employee_branches = $branch_schedule_helper->getBranchAsignments('employee_id', $employee, $ot_start_date, $ot_end_date);
                            //get employee schedules
                            $schedules = $branch_schedule_helper->getEmployeeSchedules($employee_branches['employees'], $ot_start_date, $ot_end_date);
                            //populate employee memos
                            $memos = $branch_schedule_helper->getEmployeeMemos($employee_branches['departments'], $employee_branches['employees'], $ot_start_date, $ot_end_date);
                            //populate employee schedules
                            $employee_schedules = $branch_schedule_helper->populateEmployeeSchedules($employee_branches['employees'], $schedules, $previous_day, $last_day, $employee_branches, $memos);
                            //get employee schedule
                            //get employee schedule and memo
                            $emp_sched_memo = $branch_schedule_helper->findEmployeeSchedule($employee_id, $ot_start_date, $employee_schedules);
                            $emp_schedule = $emp_sched_memo['schedule'];

                            //get employee service records
                            // $total_working_hours = $emp_schedule['logins']['first_period_working_hours'] + $emp_schedule['logins']['second_period_working_hours'];
                            if ($emp_schedule != "noSched") {
                                $total_working_hours = $emp_schedule['logins']['first_period_working_hours'] + $emp_schedule['logins']['second_period_working_hours'];
                            } else {
                                $total_working_hours = $employee_schedules[$employee_id]['rest_day_pay'];
                            }

                            if (!isset($payroll_config['payroll_type']) || $payroll_config['payroll_type'] === 0) { // * PRIVATE
                                $emp_service = $branch_schedule_helper->getServiceRecord($employee_branches['employees'], $ot_start_date, $ot_end_date);
                                $service = $branch_schedule_helper->getEmployeeServiceRecordInfo($emp_service[$employee_id], $ot_start_date, $total_working_hours);
                            } else {
                                if ($payroll_config['payroll_type'] === 1) { // * GOVERNMENT
                                    $service = $branch_schedule_helper->getPlantillaRecord($employee_id, $ot_start_date);
                                }
                            }

                            $dt = new DateTime($ot_start_date);

                            // Declare $emp_branch based on expected values of $branch_department_info.
                            $emp_branches = $employee_branches['branch_records'][$employee_id]['branches'];

                            // Declare $latest_start_date based on expected values of $branch_department_info.
                            $latest_start_date = $employee_branches['branch_records'][$employee_id]['latest_start_date'];

                            $branch_department_info = $branch_schedule_helper->getEmployeeBranchDepartmentRecordInfo($emp_branches, $latest_start_date);
                            $branch_id = $branch_department_info['result'] ? $branch_department_info['branch_department']['branch_id'] : 0; // Cannot get Value of the branch_id
                            $service["service_record"]["rate"] = $this->rateComputationsforBasicsalary($branch_id, $service["service_record"]['salary'], $service["service_record"]['rate_type'], $total_working_hours);

                            $rate = $service["service_record"]["rate"];
                            // End Here

                            $holidays = isset($payroll_info[$ot_start_date]['holiday_in_date']) ? $payroll_info[$ot_start_date]['holiday_in_date'] : array();
                            $emp_schedule = $payroll_info[$ot_start_date]['emp_sched_memo']['schedule'];
                            $service = $payroll_info[$ot_start_date]['service'];
                            $total_working_hours = $payroll_info[$ot_start_date]['total_working_hours'];
                            // $rest_day = $payroll_info[$ot_start_date]['is_rest_day'];
                        }
                        if (!$service['result']) {
                            continue;
                        }
                        // if ($overtime_info["honored_hours"] > 0) {
                        //     if (
                        //         $overtime_info["honored_hours"] != "0"
                        //         && $overtime_info["honored_hours"] != "0.00"
                        //         && $overtime_info["honored_hours"] != ""
                        //         && $overtime_info["honored_hours"] > 0
                        //     ) {
                        //         $nt = new DateTime($ot_start_date_time->format('Y-m-d H:i:s'));
                        //         if (strpos($overtime_info["honored_hours"], ".") !== false) {
                        //             $fdif = $overtime_info["honored_hours"];
                        //             $f2hrm = explode(".", $fdif);
                        //             $ot_start_date_time->modify('+' . $f2hrm[0] . ' hours');
                        //             $f2m = "0." . $f2hrm[1];
                        //             $f2min = $f2m * 60;
                        //             if (strpos($f2min, ".") !== false) {
                        //                 $min_sec = explode(".", $f2min);
                        //                 $nt->modify('+' . $min_sec[0] . ' minutes');
                        //                 $seconds_dec = "0." . $min_sec[1];
                        //                 $sec = $seconds_dec * 60;
                        //                 $sec = round($sec, 0);
                        //                 $nt->modify('+' . $sec . ' seconds');
                        //             } else {
                        //                 $nt->modify('+' . $f2min . ' minutes');
                        //             }
                        //         } else {
                        //             $nt->modify('+' . $overtime_info[0]["honored_hours"] . ' hours');
                        //         }
                        //         $ot_end_date_time = new DateTime($nt->format("Y-m-d H:i:s"));
                        //     }
                        // }
                        $log["in"]["time"] = $ot_start_date_time->format('H:i:s');
                        $log["out"]["time"] = $ot_end_date_time->format('H:i:s');
                        $log["in"]["date_time"] = $ot_start_date_time->format('Y-m-d H:i:s');
                        $log["out"]["date_time"] = $ot_end_date_time->format('Y-m-d H:i:s');
                        $log["in"]["date"] = $ot_start_date_time->format('Y-m-d');
                        $log["out"]["date"] = $ot_end_date_time->format('Y-m-d');
                        $total_hours = (new DateTimeHelper)->dateHourDiff($log["in"]["date_time"], $log["out"]["date_time"]);
                        // if ($emp_schedule != "noSched") {
                        //     $excess_hours = $total_hours - $total_working_hours;
                        //     if ($excess_hours <= 0) {
                        //         $excess_hours = 0;
                        //     }
                        // } else {
                        //     $excess_hours = $total_hours - ($total_working_hours * 2);
                        //     if ($excess_hours <= 0) {
                        //         $excess_hours = 0;
                        //     }
                        // }
                        // $excess_hours = $emp_schedule != "noSched" ?  '0' : '0'

                        /** OLD CODE */
                        // $regular_hours = $total_hours - $excess_hours;
                        /** NEW CODE */
                        $regular_hours = round($total_hours, 2);

                        $night_diff_hours = $dtr_getter->getDtrNightDiff($ot_start_date, $ot_end_date, $log);

                        //exit the overtime pay on restday
                        if ($emp_schedule == 'noSched') {
                            continue;
                        }
                        if ($overtime_info['additional_pay_type'] == 1) {
                            $ot = $this->computeOvertimeHoliday(
                                $ot_start_date,
                                $branch_id,
                                $regular_hours,
                                $excess_hours = 0,
                                $night_diff_hours,
                                $rest_day,
                                $holidays,
                                $rate['hRate'],
                                $rate['dRate'],
                                $service,
                                $employee_overtime_and_holidays,
                                $log
                            );
                            $ot['result']['payroll_id'] = $payroll_id;

                            $total_regular += $ot['result']['total_amount'];
                            $total_ot_holidays += $ot['result']['holiday_ot_amount'];
                            array_push($ot_on_holidays, $ot['result']['ot_on_holidays']);
                            unset($ot['result']['ot_on_holidays']);
                            // unset($ot['result']['holiday_ot_amount']);
                            array_push($overtime, $ot);
                        } else {
                            $ot = $this->computeRegularPay($ot_start_date, $regular_hours, $rate['hRate']);
                            $ot['result']['payroll_id'] = $payroll_id;
                            array_push($overtime, $ot);
                            $total_regular += $ot['result']['total_amount'];
                        }

                        // if (count($ot) > 0 && $overtime_info['payroll_id'] == 0) {
                        //     $this->db->get('emp_otholiday', null);
                        //     $this->db->where('id', $overtime_info['id']);
                        //     $this->db->update('emp_otholiday', ['payroll_id' => $payroll_id]);
                        // }
                    } else {
                        continue;
                    }
                }
            }
        }


        return array(
            'overtime' => $overtime,
            'total_regular' => $total_regular,
            'total_ot_holidays' => $total_ot_holidays,
            'ot_on_holidays' => $ot_on_holidays
        );
    }

    /**
     * Compute Overtime
     *
     * @param string $date
     * @param string $branch_id
     * @param int $regular_hours
     * @param int $excess_hours
     * @param int $night_diff_hours
     * @param bool $rest_day
     * @param array $holidays
     * @param int $hourly_rate
     * @param int $daily_rate
     * @param array $service
     * @param array $employee_overtime_and_holiday
     *
     * @return array
     * @throws \Exception
     */
    public function computeOvertimeHoliday(
        $date,
        $branch_id,
        $regular_hours,
        $excess_hours,
        $night_diff_hours,
        $rest_day,
        $holidays,
        $hourly_rate,
        $daily_rate,
        $service,
        $employee_overtime_and_holiday,
        $log
    ) {
        $configuration = $this->configuration['payroll_config'][$branch_id]; // cannot get branch_id and configuration_id
        // $configuration = $configuration[$branch_id];
        $ot_on_holidays = array(
            "date",
            "ot_holiday_amount"
        );
        $overtime_holiday_rate = ($configuration['regular_overtime_percentage'] + 100) / 100;
        $night_diff_rate = (($configuration['night_diff_rate'] + 100) / 100);

        $ot_holiday = $employee_overtime_and_holiday['data'];

        $start_date_time = "";
        $end_date_time = "";

        // //on holidays only NOT the whole overtime that exceeds holiday
        // $ot_holiday_time = 0;
        // $nd_holiday_time = 0;

        // $regular_holiday_rate = 1;
        // $special_holiday_rate = 1;
        // $total_holiday_pay = 0;

        $excess_rate = 0;
        $new_rate = 0;

        $holiday_rate = 1;


        foreach ($ot_holiday as $ot_holidays) {
            //get the start date and end date
            $start_date = new DateTime($ot_holidays['start_date_time']);
            $start_date = $start_date->format('Y-m-d');
            $start_date_time = new DateTime($ot_holidays['start_date_time']);

            $end_date = new DateTime($ot_holidays['end_date_time']);
            $end_date = $end_date->format('Y-m-d');
            $end_date_time = new DateTime($ot_holidays['end_date_time']);

            $nd_start_date_time = 0;
            $nd_end_date_time = 0;
            $is_holiday = false;

            //$this->configuration['night_diff_configuration'] = array('night_diff_start' => '22:00:00', 'night_diff_end' => '6:00:00');
            $nd_diff_config = $this->configuration['night_diff_configuration'];

            //check if the date is equivalent to current date
            if ($start_date === $date) {
                $ot_nd_data = array("");
                foreach ($holidays as $holiday) {
                    $holiday_date = new DateTime($holiday['date']);
                    $holiday_date = $holiday_date->format('Y-m-d');
                    //check check if holiday date is
                    if ($holiday_date === $start_date) {

                        //add holiday Type if special or regular
                        if ($holiday['type'] === 0) {
                            // $special_holiday_rate *= (($configuration['special_holiday_percentage'])/100);
                            $overtime_holiday_rate = (($configuration['special_holiday_overtime_percentage'] + 100) / 100);
                            $holiday_rate *= (($configuration['special_holiday_percentage'] + 100) / 100);
                            $night_diff_rate = (($configuration['special_holiday_night_diff_percentage'] + 100) / 100);
                            $is_holiday = true;
                        } else {
                            // $regular_holiday_rate *= (($configuration['regular_holiday_percentage'])/100);
                            $overtime_holiday_rate = (($configuration['regular_holiday_overtime_percentage'] + 100) / 100);
                            $holiday_rate *= (($configuration['regular_holiday_percentage'] + 100) / 100);
                            $night_diff_rate = (($configuration['regular_holiday_night_diff_percentage'] + 100) / 100);
                            $is_holiday = true;
                        }

                        //check if the end date is similar to holiday date
                        // $end_date_time = ($holiday_date === $end_date)? new DateTime($ot_holidays['end_date_time']) : new DateTime($end_date." 00:00:00");
                        // $time_diff = $start_date_time->diff($end_date_time);
                        // $ot_holiday_time += $time_diff->h + ($time_diff->days * 24);
                        // $minutes = $time_diff->i;
                        // $ot_holiday_time += $minutes/60;

                        //if morning ot with ND

                        //if($ot_holidays['start_date_time'] < $date." 06:00:00"){

                        // if($ot_holidays['start_date_time'] < $date." ".$nd_diff_config['night_diff_end']) {
                        //     $nd_holiday_start_time = new DateTime($ot_holidays['start_date_time']);

                        //     $nd_holiday_end_time = ($ot_holidays['start_date_time'] > $date." ".$nd_diff_config['night_diff_end'])? new DateTime($date." ".$nd_diff_config['night_diff_end']): new DateTime($ot_holidays['end_date_time']);


                        //     $nd_time_diff = $nd_holiday_start_time->diff($nd_holiday_end_time);

                        //     $nd_holiday_time += $nd_time_diff->h + ($nd_time_diff->days * 24);
                        //     $nd_minutes = $nd_time_diff->i;
                        //     $nd_holiday_time += $nd_minutes/60;

                        // } elseif ($ot_holidays['end_date_time'] > $date." ".$nd_diff_config['night_diff_start']) {
                        //     // $nd_holiday_start_time = new DateTime($ot_holidays['start_date_time']);

                        //     $date_plus_one = new DateTime($date);
                        //     $date_plus_one->modify('+1 day');
                        //     $date_plus_one = $date_plus_one->format('Y-m-d');

                        //     $nd_holiday_start_time = ($ot_holidays['start_date_time'] < $date." ".$nd_diff_config['night_diff_start'])? new DateTime($date." ".$nd_diff_config['night_diff_start']): new DateTime($ot_holidays['start_date_time']);
                        //     $nd_holiday_end_time = ($ot_holidays['end_date_time'] > $date_plus_one." 00:00:00")? new DateTime($date_plus_one." 00:00:00"): new DateTime($ot_holidays['end_date_time']);

                        //     //add condition for below 10pm  for start time

                        //     $nd_time_diff = $nd_holiday_start_time->diff($nd_holiday_end_time);

                        //     $nd_holiday_time += $nd_time_diff->h + ($nd_time_diff->days * 24);
                        //     $nd_minutes = $nd_time_diff->i;
                        //     $nd_holiday_time += $nd_minutes/60;

                        // }

                    }
                }
                // // ot_on_holiday
                // array_push()
            }
        }


        //compute for OT and ND for the holiday
        // $night_diff_rate = (($configuration['night_diff_rate']+100)/100);
        $ot_holiday_amount = $hourly_rate * $regular_hours * $overtime_holiday_rate;
        $nd_holiday_amount = (($hourly_rate * $night_diff_hours * $overtime_holiday_rate * (($configuration['night_diff_rate'] + 100) / 100)) - ($hourly_rate * $night_diff_hours * $overtime_holiday_rate));

        $total_ot_holiday_amount = (($ot_holiday_amount + $nd_holiday_amount) * $holiday_rate) - ($ot_holiday_amount + $nd_holiday_amount);

        $ot_on_holidays = array(
            "date" => $date,
            "ot_holiday_amount" => bcdiv($total_ot_holiday_amount, 1, 2)
        );

        // $regular_hours_amount = 0;
        // $night_diff_hours_amount = 0;
        $excess_hours_amount = 0;

        // // RATE 1.25
        $percentage_rate = $overtime_holiday_rate;


        // $special = array("total" => 0, "percentage" => 0);
        // $regular = array("total" => 0, "percentage" => 0);
        // $number_of_holidays_validated = 0;

        $overtime_type = 0;

        $status = $this->getEmploymentStatus($service);
        $employment_status = json_decode($this->configuration['payroll_config'][$branch_id]['employment_status'], true);

        $has_ot_pay = $employment_status[$status]['overtimePay'] === 1;

        $total_amount = 0;
        //seperate "OT" and "OT with ND" Computations on regular days from "OT" and "OT with ND" computation on holidays

        $night_diff_hours_amount = $regular_hours_amount = 0;
        if ($has_ot_pay) {

            $regular_hours_amount = $hourly_rate * $regular_hours * $overtime_holiday_rate;
            $night_diff_hours_amount = ($hourly_rate * $night_diff_hours * $overtime_holiday_rate * (($configuration['night_diff_rate'] + 100) / 100)) - ($hourly_rate * $night_diff_hours * $overtime_holiday_rate);

            $total_amount = $night_diff_hours_amount + $regular_hours_amount + $total_ot_holiday_amount;
        }

        $holiday_rate = $is_holiday ? $holiday_rate : 0;

        $result = array(
            "date" => $date,
            "overtime_type_id" => $overtime_type,
            "regular_hours" => $regular_hours,
            "regular_amount" => round($regular_hours_amount, 2),
            "regular_percentage" => $percentage_rate,
            "night_differential_hours" => $night_diff_hours,
            "night_differential_amount" => round($night_diff_hours_amount, 2),
            "night_differential_percentage" => $night_diff_rate,
            "excess_hours" => $excess_hours,
            "excess_amount" => round($excess_hours_amount, 2),
            "excess_percentage" => $new_rate,
            // "holiday_ot_amount" => bcdiv($total_ot_holiday_amount, 1, 2),
            "holiday_ot_percentage" => bcdiv($holiday_rate, 1, 2),
            "total_amount" => bcdiv($total_amount, 1, 2),
            "holiday_ot_amount" => round($total_ot_holiday_amount, 2),
            "ot_on_holidays" => $ot_on_holidays
        );

        return array("result" => $result, "configuration" => $configuration);
    }
    /**
     * Compute Regular pay
     *
     * @param string $hours
     * @param array $hourly_rate
     * @return array
     * @throws \Exception
     */
    public function computeRegularPay($date, $hours, $hourly_rate)
    {
        $result = array(
            "date" => $date,
            "overtime_type_id" => 4,
            "regular_hours" => $hours,
            "regular_amount" => bcdiv(($hours * $hourly_rate), 1, 2),
            "regular_percentage" => 0,
            "night_differential_hours" => 0,
            "night_differential_amount" => 0,
            "night_differential_percentage" => 0,
            "excess_hours" => 0,
            "excess_amount" => 0,
            "excess_percentage" => 0,
            "total_amount" => bcdiv(($hours * $hourly_rate), 1, 2),

        );
        return array("result" => $result, "configuration" => "N/A");
    }

    /**
     * Fetch Employment Status of an employee
     * @param $service
     */
    private function getEmploymentStatus($service)
    {
        $emp_status = null;
        switch ($service['service_record']['employment_status']) {
            case 0:
                $emp_status = 'probationary';
                break;
            case 1:
                $emp_status = 'regular';
                break;
            case 2:
                $emp_status = 'managerial';
                break;
        }
        return $emp_status;
    }

    /**
     * Apply late policy
     *
     * @param array $late
     * @param array $configuration_late
     * @param string $start_time
     * @return array
     * @throws \Exception
     */
    public function latePolicy($time_in, $late, $rules, $employment_status, $service)
    {
        if (
            !is_null($time_in) &&
            $employment_status['lateDeduction'] == 1 &&
            COUNT($rules) > 0 &&
            $late['late'] > 0
        ) {

            $rule = array_filter(
                $rules,
                function ($rule) use ($time_in) {
                    return  $rule['range1'] <= $time_in && $time_in <= $rule['range2'];
                }
            );

            if (empty($rule)) {
                $rule = array_filter(
                    $rules,
                    fn ($rule) =>  empty($rule['range1']) && empty($rule['range2'])
                );
                if (empty($rule)) {
                    $rule = array_filter(
                        $rules,
                        fn ($rule) => $rule['affectedTime'] == 0
                    );
                }
            }

            $late['amount'] = 0;
            $late['has_late_config'] = true;
            $late['penalty'] = 0;
            $late['absent'] = 0;
            if (!empty($rule)) {
                if (end($rule)['considerAbsent'] == 1) {
                    $late['absent'] = 1;
                } else {
                    if (end($rule)['customValueCheck'] == 1) {
                        $custom_value = end($rule)['customValue'];
                        $late['amount'] = $custom_value;
                        if (end($rule)['multiplierCheck'] == 1) {
                            $multiplier = end($rule)['multiplier'];
                            $total_late = $late['late'] / 60;
                            $late['amount'] = ($total_late * $multiplier) * $service['service_record']['rate']['hRate'] + $custom_value;
                        }
                    } else {
                        if (end($rule)['multiplierCheck'] == 1) {
                            $multiplier = end($rule)['multiplier'];
                            $total_late = $late['late'] / 60;
                            $late['amount'] = ($total_late * $multiplier) * $service['service_record']['rate']['hRate'];
                        } else {
                            $late['amount'] = 0;
                            $late['has_late_config'] = false;
                        }
                    }
                }
            } else {
                $late['amount'] = 0;
                $late['has_late_config'] = false;
            }
        } else {
            $late['amount'] = 0;
            $late['has_late_config'] = false;
        }
        return $late;
    }

    /**
     * Compute Lates
     *
     * This Function Computes the total lates of employee Per period.
     *
     * @param string $lates
     * @param int $service
     * @return array
     * @throws \Exception
     */
    public function computeLates(
        $lates,
        $service,
        $absent,
        $employment_status,
        $has_config,
        $amount = 0
    ) {
        $status = $this->getEmploymentStatus($service);
        $late_amount = 0;

        if (array_key_exists($status, $employment_status)) {
            //Check in Payroll Configuration if Late deduction is allowed on Employee Status of Employee
            if ($employment_status[$status]['lateDeduction'] == 0) {
                $late_amount = 0;
            } else {
                if ($lates >= 1 && !$absent) {
                    //Check if had late rule on Payroll configuration
                    if ($has_config) {
                        $late_amount = $amount;
                    } else {
                        $computed_minutes = round($lates, 2);
                        $diff = ($computed_minutes / 60);
                        $late_amount = bcdiv($service['service_record']["rate"]['hRate'] * $diff, 1, 2);
                    }
                }
            }
        } else {
            if ($lates >= 1 && !$absent) {
                //Check if had late rule on Payroll configuration
                if ($has_config) {
                    $late_amount = $amount;
                } else {
                    $computed_minutes = round($lates, 2);
                    $diff = ($computed_minutes / 60);
                    $late_amount = bcdiv($service['service_record']["rate"]['hRate'] * $diff, 1, 2);
                }
            }
        }
        return $late_amount;
    }

    /**
     * Get Employee lates
     *
     * @param float $late
     * @param string $date
     * @param array $service
     * @return array
     * @throws \Exception
     */
    public function getEmployeeLates(
        $evaluation,
        $service,
        $emp_schedule,
        $branch_id,
        $date
    ) {
        $params = $this->params;

        $emp_status = [
            0 => 'probationary',
            1 => 'regular',
            2 => 'managerial'
        ];

        $emp_status_index = $service['service_record']["employment_status"];

        $employment_status = json_decode($this->configuration['payroll_config'][$branch_id]['employment_status'], true);

        $late_params = json_decode($params['late_rules'], true);

        if ($params['late_rules'] != "[]") {
            foreach ($late_params['late_dates'] as $key_dates) {
                $date_array = $key_dates;
            }
        } else {
            $date_array = [];
        }

        if (in_array($date, $date_array)) {
            $this->getCustomPayrollConfiguration($this->employee_branches['branches']);
            $configuration_late = array('rules' => json_decode(json_encode($this->configuration['payroll_config'][$branch_id]['late_rules']), true), 'combine_late_periods' => $this->configuration['payroll_config'][$branch_id]['combine_late_periods']);
        } else {
            $this->getPayrollConfiguration($this->employee_branches['branches']);
            $configuration_late = array('rules' => json_decode(json_encode($this->configuration['payroll_config'][$branch_id]['late_rules']), true), 'combine_late_periods' => $this->configuration['payroll_config'][$branch_id]['combine_late_periods'], 'employment_status' => $employment_status);
        }
        if ($configuration_late['combine_late_periods']) {
            $all_lates['first']['late'] = (int) (($evaluation["1st"]["total_lates"] + $evaluation["2nd"]["total_lates"]) * 60);
            $all_lates['second']['late'] = 0;
        } else {
            $all_lates['first']['late'] = (int) ($evaluation["1st"]["total_lates"] * 60);
            $all_lates['second']['late'] = (int) ($evaluation["2nd"]["total_lates"] * 60);
        }
        $all_lates["service"] = $service;
        $first_log = array_key_exists('set_logs_new', $evaluation['1st']);
        $all_lates['first'] = $this->latePolicy(
            $first_log ? $evaluation['1st']['set_logs_new'][0]['in']['time'] : null,
            $all_lates['first'],
            $configuration_late['rules'],
            $employment_status[$emp_status[$emp_status_index]],
            $service
        );
        $second_log = array_key_exists('set_logs_new', $evaluation['2nd']);
        $all_lates['second'] = $this->latePolicy(
            $second_log ? $evaluation['2nd']['set_logs_new'][0]['in']['time'] : null,
            $all_lates['second'],
            $configuration_late['rules'],
            $employment_status[$emp_status[$emp_status_index]],
            $service
        );
        $all_lates['first']['absent'] = isset($all_lates['first']['absent']) ? $all_lates['first']['absent'] : false;
        $all_lates['second']['absent'] = isset($all_lates['second']['absent']) ? $all_lates['second']['absent'] : false;
        $all_lates['first']['late'] = round(($all_lates['first']['late']), 2);
        $all_lates['second']['late'] = round(($all_lates['second']['late']), 2);
        $first_has_config = array_key_exists('has_late_config', $all_lates['first']) ? $all_lates['first']['has_late_config'] : false;
        $second_has_config = array_key_exists('has_late_config', $all_lates['second']) ? $all_lates['second']['has_late_config'] : false;
        $all_lates["total_late_minutes"] = $evaluation["1st"]["total_lates"] + $evaluation["2nd"]["total_lates"];
        $all_lates['first']['amount'] = $this->computeLates(
            $all_lates['first']['late'],
            $service,
            $all_lates['first']['absent'],
            $configuration_late['employment_status'],
            $first_has_config,
            $all_lates['first']['amount']
        );
        $all_lates['second']['amount'] = $this->computeLates(
            $all_lates['second']['late'],
            $service,
            $all_lates['second']['absent'],
            $configuration_late['employment_status'],
            $second_has_config,
            $all_lates['second']['amount']
        );
        $all_lates["total_amount"] = $all_lates['first']['amount'] + $all_lates['second']['amount'];
        $all_lates["total_lates"] = (int) (($evaluation["1st"]["total_lates"] + $evaluation["2nd"]["total_lates"]) * 60);
        $all_lates['penalty'] = $all_lates["total_amount"] > 0 ? true : false;
        // if ($emp_status_index === 2) {
        //     $all_lates["total_amount"] = 0;
        // }
        return $all_lates;
    }

    /**
     * Apply Undertime Policy
     *
     * @param array $underTime
     * @param array $configuration_undertime
     * @param string $end_time
     * @return array
     * @throws \Exception
     */
    public function undertimePolicy($underTime, $configuration_undertime, $end_time, $service)
    {
        if (COUNT($configuration_undertime['rules']) > 0 && $underTime['undertime'] > 0) {
            foreach ($configuration_undertime['rules'] as $rules) {
                $end = strtotime($end_time);
                $first_undertime_config = strtotime($rules['range1']);
                $first_undertime_interval = round(($end - $first_undertime_config) / 60);

                $first_undertime_range = $first_undertime_interval;

                $second_undertime_config = strtotime($rules['range2']);
                $second_undertime_interval = round(($end - $second_undertime_config) / 60);

                $second_undertime_range = $second_undertime_interval;

                $underTime['amount'] = 0;
                if (
                    $rules['affectedTime'] == "all" || (($underTime['undertime'] <= $first_undertime_range && $underTime['undertime'] >= $second_undertime_range))
                ) {
                    if ($rules['considerAbsent'] == 1) {
                        // consider as absent
                        $underTime['absent'] = true;
                        $underTime['penalty'] = 0;
                        $underTime['amount'] = 0;
                    } else {
                        if ($rules['undertimeDeduction'] == 1) {
                            // no undertime deduction
                            $underTime['absent'] = false;
                            $underTime['amount'] = 0;
                            $underTime['has_undertime_config'] = true;
                        } else {
                            if ($rules['customValueCheck'] == 1) {
                                if ($rules['multiplierCheck'] == 1) {
                                    // multiply undertime plus custom value undertime
                                    $total_undertime = $underTime['undertime'] / 60;
                                    $underTime['amount'] = ($total_undertime * $rules['multiplier']) * $service['service_record']['rate']['hRate'] + $rules['customValue'];
                                    $underTime['absent'] = false;
                                    $underTime['penalty'] = 0;
                                    $underTime['has_undertime_config'] = true;
                                } else {
                                    // custom undertime
                                    $underTime['amount'] = $rules['customValue'];
                                    $underTime['absent'] = false;
                                    $underTime['penalty'] = 0;
                                    $underTime['has_undertime_config'] = true;
                                }
                            } else {
                                if ($rules['multiplierCheck'] == 1) {
                                    // multiply undertime
                                    $total_undertime = $underTime['undertime'] / 60;
                                    $underTime['amount'] = ($total_undertime * $rules['multiplier']) * $service['service_record']['rate']['hRate'];
                                    $underTime['absent'] = false;
                                    $underTime['penalty'] = 0;
                                    $underTime['has_undertime_config'] = true;
                                } else {
                                    $underTime['amount'] = 0;
                                    $underTime['has_undertime_config'] = false;
                                }
                            }
                        }
                    }
                } else {
                    $underTime['amount'] = 0;
                    $underTime['has_undertime_config'] = false;
                }
            }
        } else {
            $underTime['amount'] = 0;
            $underTime['has_undertime_config'] = false;
        }
        return $underTime;
    }

    /**
     * Compute Undertime
     *
     * @param string $undertime
     * @param string $service
     * @return array
     * @throws \Exception
     */
    public function computeUndertime($undertime_minutes, $amount_undertime, $service, $employment_status, $absent, $has_config = false)
    {
        $undertime_amount = 0;
        $status = $this->getEmploymentStatus($service);
        if (array_key_exists($status, $employment_status)) {
            if ($employment_status[$status]['undertimeDeduction'] == 0) {
                $undertime_amount = 0;
            } else {
                if ($undertime_minutes > 0 && !$absent) {
                    if ($has_config) {
                        $undertime_amount = $amount_undertime;
                    } else {
                        $computed_minutes = intval($undertime_minutes);
                        $diff = ($computed_minutes / 60);
                        $undertime_amount = bcdiv($service['service_record']["rate"]['hRate'] * $diff, 1, 2);
                    }
                }
            }
        } else {
            if ($undertime_minutes > 0 && !$absent) {
                if ($has_config) {
                    $undertime_amount = $amount_undertime;
                } else {
                    $computed_minutes = intval($undertime_minutes);
                    $diff = ($computed_minutes / 60);
                    $undertime_amount = bcdiv($service['service_record']["rate"]['hRate'] * $diff, 1, 2);
                }
            }
        }
        return $undertime_amount;
    }

    /**
     * Get Employee Undertime
     *
     * @param float $underTime
     * @param string $date
     * @param array $service
     * @return array
     * @throws \Exception
     */
    public function getEmployeeUndertime($evaluation, $service, $emp_schedule, $branch_id, $date)
    {
        $params = $this->params;

        $undertime_params = json_decode($params['undertime_rules'], true);

        if ($params['undertime_rules'] != "[]") {
            foreach ($undertime_params['undertime_dates'] as $key_dates) {
                $date_array = $key_dates;
            }
        } else {
            $date_array = [];
        }

        if (in_array($date, $date_array)) {
            $this->getCustomPayrollConfiguration($this->employee_branches['branches']);
            $configuration_undertime = array('rules' => json_decode(json_encode($this->configuration['payroll_config'][$branch_id]['undertime_rules']), true));
        } else {
            $this->getPayrollConfiguration($this->employee_branches['branches']);
            $configuration_undertime = array('rules' => json_decode(json_encode($this->configuration['payroll_config'][$branch_id]['undertime_rules']), true), 'employment_status' => json_decode($this->configuration['payroll_config'][$branch_id]['employment_status'], true));
        }

        $all_undertime['first']['undertime'] = $evaluation["1st"]["total_undertime"] * 60;
        $all_undertime['second']['undertime'] = $evaluation["2nd"]["total_undertime"] * 60;

        $all_undertime["service"] = $service;
        $all_undertime['first'] = $this->undertimePolicy($all_undertime['first'], $configuration_undertime, $emp_schedule['first_time_out'], $service);
        $all_undertime['second'] = $this->undertimePolicy($all_undertime['second'], $configuration_undertime, $emp_schedule['second_time_out'], $service);
        $all_undertime['penalty'] = false;
        $all_undertime['first']['absent'] = isset($all_undertime['first']['absent']) ? $all_undertime['first']['absent'] : false;
        $all_undertime['second']['absent'] = isset($all_undertime['second']['absent']) ? $all_undertime['second']['absent'] : false;
        $all_undertime['first']['amount'] = round(($all_undertime['first']['amount']), 2);
        $all_undertime['second']['amount'] = round(($all_undertime['second']['amount']), 2);
        $all_undertime["total_undertime_minutes"] = $evaluation["1st"]["total_undertime"] + $evaluation["2nd"]["total_undertime"];
        $all_undertime['first']['amount'] = $this->computeUndertime($all_undertime['first']['undertime'], $all_undertime['first']['amount'], $service, $configuration_undertime['employment_status'], $all_undertime['first']['absent'], $all_undertime['first']['has_undertime_config']);
        $all_undertime['second']['amount'] = $this->computeUndertime($all_undertime['second']['undertime'], $all_undertime['second']['amount'], $service, $configuration_undertime['employment_status'], $all_undertime['second']['absent'], $all_undertime['second']['has_undertime_config']);
        $all_undertime["total_amount"] = $all_undertime['first']['amount'] + $all_undertime['second']['amount'];
        $all_undertime["total_undertime"] = ($evaluation["1st"]["total_undertime"] + $evaluation["2nd"]["total_undertime"]) * 60;
        // if ($service['service_record']["employment_status"] === 2) {
        //     $all_undertime["total_amount"] = 0;
        // }
        return $all_undertime;
    }

    /**
     * Apply Overbreak Policy
     * @param array $start_time
     * @param string $end_time
     * @param array $overbreak
     * @param array $configuration_overbreak
     * @param string $break_hours
     */
    public function overbreakPolicy($overbreak, $configuration_overbreak, $all_overbreak, $service, $logs)
    {
        $config_rules = json_decode($configuration_overbreak['rules']);
        if (count($config_rules) > 0 && $overbreak['period_breaks'] > 0) {
            if (is_array($logs) && count($logs)) {
                foreach ($config_rules as $rules) {
                    $rules_decode = json_decode(json_encode($rules), true);
                    // $has_valid_in_config = false;

                    // Get the Details of Break Logs
                    $start_break = strtotime($logs[0]['in']['time']);
                    $start_break_interval = round(($start_break) / 60);
                    $finish_break = strtotime($logs[0]['out']['time']);
                    $finish_break_interval = round(($finish_break) / 60);

                    //Get the details of Overbreak Config rule
                    $first_overbreak_config = strtotime($rules_decode['range1']);
                    $first_overbreak_interval = round(($first_overbreak_config) / 60);
                    $second_overbreak_config = strtotime($rules_decode['range2']);
                    $second_overbreak_interval = round(($second_overbreak_config) / 60);

                    // Verify whether the logs within the scope of the configuration.
                    if ($start_break_interval >= $first_overbreak_interval && $finish_break_interval <= $second_overbreak_interval) {
                        $has_valid_in_config = true;
                    } else {
                        $has_valid_in_config = false;
                    }


                    if ($rules_decode['affectedTime'] == "all" || $has_valid_in_config) {
                        if ($rules_decode['considerAbsent'] !== 0) {
                            // no overbreak deduction
                            $overbreak['absent'] = false;
                            $overbreak['has_overbreak_config'] = true;
                            $overbreak['overbreak'] = 0;
                            return $overbreak;
                        } else {
                            if ($rules_decode['overbreakDeduction'] > 0) {
                                // no overbreak deduction
                                $overbreak['absent'] = false;
                                $overbreak['overbreak'] = 0;
                                $overbreak['has_overbreak_config'] = true;
                                return $overbreak;
                            } else {
                                if ($rules_decode['customValueCheck'] === 1) {
                                    if ($rules_decode['multiplierCheck'] === 1) {
                                        // multiply overbreak plus custom value overbreak
                                        $total_overbreak = $overbreak['period_breaks'] / 60;
                                        $overbreak['overbreak'] = ($total_overbreak * $rules_decode['multiplier']) * $service['service_record']['rate']['hRate'] + $rules_decode['customValue'];
                                        $overbreak['absent'] = false;
                                        $overbreak['penalty'] = 0;
                                        $overbreak['has_overbreak_config'] = true;
                                        return $overbreak;
                                    } else {
                                        // custom overbreak value
                                        $overbreak['overbreak'] = $rules_decode['customValue'];
                                        $overbreak['absent'] = false;
                                        $overbreak['penalty'] = 0;
                                        $overbreak['has_overbreak_config'] = true;
                                        return $overbreak;
                                    }
                                } else {
                                    if ($rules_decode['multiplierCheck'] === 1) {
                                        // multiply overbreak
                                        $total_overbreak = $overbreak['period_breaks'] / 60;
                                        $overbreak['overbreak'] = ($total_overbreak * $rules_decode['multiplier']) * $service['service_record']['rate']['hRate'];
                                        $overbreak['absent'] = false;
                                        $overbreak['has_overbreak_config'] = true;
                                        $overbreak['penalty'] = 0;
                                        return $overbreak;
                                    } else {
                                        $overbreak['overbreak'] = 0;
                                        $overbreak['has_overbreak_config'] = false;
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                $overbreak['overbreak'] = 0;
                $overbreak['has_overbreak_config'] = false;
                return $overbreak;
            }
        } else {
            $overbreak['overbreak'] = 0;
            $overbreak['has_overbreak_config'] = false;
            return $overbreak;
        }
    }

    /**
     * Compute Overbreak
     *
     * @param float $overbreak
     * @param string $service
     * @return array
     * @throws \Exception
     */
    public function computeOverbreak($overbreak, $service, $absent, $employment_status, $amount_overbreak, $has_config = false)
    {
        $overbreak_amount = 0;
        $status = $this->getEmploymentStatus($service);
        if (array_key_exists($status, $employment_status)) {
            if ($employment_status[$status]['overbreakDeduction'] == 0) {
                $overbreak_amount = 0;
            } else {
                if ($overbreak > 0 && !$absent) {
                    if ($has_config) {
                        $overbreak_amount = $amount_overbreak;
                    } else {
                        $computed_minutes = round($overbreak, 2);
                        $diff = ($computed_minutes / 60);
                        $overbreak_amount = bcdiv($service['service_record']["rate"]['hRate'] * $diff, 1, 2);
                    }
                }
            }
        } else {
            if ($overbreak > 0 && !$absent) {
                if ($has_config) {
                    $overbreak_amount = $amount_overbreak;
                } else {
                    $computed_minutes = round($overbreak, 2);
                    $diff = ($computed_minutes / 60);
                    $overbreak_amount = bcdiv($service['service_record']["rate"]['hRate'] * $diff, 1, 2);
                }
            }
        }
        return $overbreak_amount;
    }

    /**
     * Get Employee Overbreak
     *
     * @param float $overbreak
     * @param string $date
     * @param array $service
     * @return array
     * @throws \Exception
     */
    public function getEmployeeOverbreak($evaluation, $service, $emp_schedule, $branch_id, $date, $break_logs)
    {
        $params = $this->params;

        $overbreak_params = json_decode($params['overbreak_rules'], true);

        if ($params['overbreak_rules'] != "[]") {
            foreach ($overbreak_params['overbreak_dates'] as $key_dates) {
                $date_array = $key_dates;
            }
        } else {
            $date_array = [];
        }

        if (in_array($date, $date_array)) {
            $this->getCustomPayrollConfiguration($this->employee_branches['branches']);
            $configuration_overbreak = array('rules' => json_decode(json_encode($this->configuration['payroll_config'][$branch_id]['overbreak_rules']), true), 'combine_late_periods' => $this->configuration['payroll_config'][$branch_id]['combine_late_periods']);
        } else {
            $this->getPayrollConfiguration($this->employee_branches['branches']);
            $configuration_overbreak = array('rules' => json_decode(json_encode($this->configuration['payroll_config'][$branch_id]['overbreak_rules']), true), 'combine_late_periods' => $this->configuration['payroll_config'][$branch_id]['combine_late_periods'], 'employment_status' => json_decode($this->configuration['payroll_config'][$branch_id]['employment_status'], true));
        }

        $all_overbreak['first']['period_breaks'] = $evaluation["1st"]["total_breaks"] * 60;
        $all_overbreak['second']['period_breaks'] = $evaluation["2nd"]["total_breaks"] * 60;
        $first_break = $evaluation["1st"]["total_breaks"] * 60;
        $second_break = $evaluation["2nd"]["total_breaks"] * 60;

        $all_overbreak["service"] = $service;
        $all_overbreak['first'] = $this->overbreakPolicy($all_overbreak['first'], $configuration_overbreak, $emp_schedule['first_break_hours'], $all_overbreak["service"], $break_logs['1st']);
        $all_overbreak['second'] = $this->overbreakPolicy($all_overbreak['second'], $configuration_overbreak, $emp_schedule['second_break_hours'], $all_overbreak["service"], $break_logs['2nd']);
        $all_overbreak['penalty'] = false;
        $all_overbreak['first']['absent'] = isset($all_overbreak['first']['absent']) ? $all_overbreak['first']['absent'] : false;
        $all_overbreak['second']['absent'] = isset($all_overbreak['second']['absent']) ? $all_overbreak['second']['absent'] : false;
        $all_overbreak['first']['period_breaks'] = round($first_break, 2);
        $all_overbreak['second']['period_breaks'] = round($second_break, 2);
        $all_overbreak["total_overbreak_minutes"] = $evaluation["1st"]["total_breaks"] + $evaluation["2nd"]["total_breaks"];
        $all_overbreak['first']['amount'] = $this->computeOverbreak($all_overbreak['first']['period_breaks'], $service, $all_overbreak['first']['absent'], $configuration_overbreak['employment_status'], $all_overbreak['first']['overbreak'], $all_overbreak['first']['has_overbreak_config']);
        $all_overbreak['second']['amount'] = $this->computeOverbreak($all_overbreak['second']['period_breaks'], $service, $all_overbreak['second']['absent'], $configuration_overbreak['employment_status'], $all_overbreak['second']['overbreak'], $all_overbreak['second']['has_overbreak_config']);

        $all_overbreak["total_amount"] = $all_overbreak['first']['amount'] + $all_overbreak['second']['amount'];
        $all_overbreak["overbreak"] = ($evaluation["1st"]["total_breaks"] + $evaluation["2nd"]["total_breaks"]) * 60;
        // if ($service['service_record']["employment_status"] === 2) {
        //     $all_overbreak["total_amount"] = 0;
        // }
        return $all_overbreak;
    }


    /**
     * Compute Undertime
     *
     * @param string $night_diff
     * @param int $service
     * @return array
     * @throws \Exception
     */
    public function computeNightDifferential($night_diff, $service, $branch_id, $worked_hours = 0, $ot = 0)
    {
        $night_diff_amount = 0;
        $status = $this->getEmploymentStatus($service);
        $night_diff_rate = ($this->configuration['payroll_config'][$branch_id]['night_diff_rate'] / 100) + 1;
        $ot_rate = ($this->configuration['payroll_config'][$branch_id]['regular_overtime_percentage'] / 100) + 1;
        $employment_status = json_decode($this->configuration['payroll_config'][$branch_id]['employment_status'], true);
        if (array_key_exists($status, $employment_status)) {
            if ($employment_status[$status]['nightDiffPay'] === 0) {
                $night_diff_amount = 0;
            } else {
                if ($night_diff > 0) {
                    // no overtime, no ot_rate
                    // if($worked_hours <= 8.0) {
                    //     $ot_rate = 1.0;
                    // }
                    $start_time = 0;
                    $end_time = 0;
                    $total_ot_nd_hours = 0;
                    $total_nd_hours = 0;

                    if ($ot > 0) {
                        foreach ($ot as $ots) {
                            if ($ots['in'] < $ots['date'] . " 06:00:00") {

                                $start_night_diff_date = new DateTime($ots['in']);
                                $end_night_diff_date = ($ots['out'] > $ots['date'] . " 06:00:00") ? new DateTime($ots['date'] . " 06:00:00") : new DateTime($ots['out']);

                                //get difference of the 2 dates
                                $time_diff = $start_time->diff($end_time);

                                $total_hours = $time_diff->h + ($time_diff->days * 24);
                                $total_minutes = $time_diff->i;

                                //total hours ot with nd
                                $total_ot_nd_hours += $total_minutes / 60;

                                $night_diff_amount += ($service['service_record']["rate"]["hRate"] * $ot_rate  * $night_diff_rate * $total_ot_nd_hours) - ($service['service_record']["rate"]["hRate"] * $ot_rate);
                            } elseif ($ots['out'] < $ots['date'] . " 22:00:00") {
                                //concat start date to static time 10 pm
                                $start_night_diff_date = $ots['date'] . " 22:00:00";
                                $date_plus_one = new DateTime($ots['date']);

                                //add one day to current day
                                $date_plus_one->modify('+1 day');
                                //convert datetime to string
                                $date_plus_one = $date_plus_one->format("Y-m-d");
                                //concat end date static time 6am
                                $end_night_diff_date = $date_plus_one . " 06:00:00";


                                $start_time = ($start_night_diff_date <= $ots['in']) ? new DateTime($ots['in']) : new DateTime($start_night_diff_date);
                                $end_time = ($end_night_diff_date >= $ots['out']) ? new DateTime($ots['out']) : new DateTime($end_night_diff_date);

                                //get difference of the 2 dates
                                $time_diff = $start_time->diff($end_time);

                                $total_ot_nd_hours = $time_diff->h + ($time_diff->days * 24);
                                $total_minutes = $time_diff->i;

                                //total hours ot with nd
                                $total_ot_nd_hours += $total_minutes / 60;
                                $night_diff_amount += (($service['service_record']["rate"]["hRate"] * $ot_rate  * $night_diff_rate * $total_ot_nd_hours) - ($service['service_record']["rate"]["hRate"] * $ot_rate * $total_ot_nd_hours));
                            }
                        }
                    }

                    // deduct Overtime Night Differential Hours from Night Differential Hours
                    $total_nd_hours = $night_diff - $total_ot_nd_hours;

                    $night_diff_amount += (($total_nd_hours * $service['service_record']["rate"]["hRate"] * $night_diff_rate) - ($service['service_record']["rate"]["hRate"] * $total_nd_hours));




                    // else{
                    //     $night_diff_amount = $service['service_record']["rate"]["hRate"] * $ot_rate * $night_diff_rate * $night_diff;

                    // }
                    // $night_diff_amount = bcdiv($rate * $night_diff, 1, 2);





                }
            }
        } else {
            if ($night_diff > 0) {
                $rate = $service['service_record']["rate"]["hRate"] * $night_diff_rate;
                $night_diff_amount = bcdiv($rate * $night_diff, 1, 2);
            }
        }
        return $night_diff_amount;
    }
    /**
     * Get Employee Undertime
     *
     * @param float $late
     * @param string $date
     * @param array $service
     * @return array
     * @throws \Exception
     */
    public function getEmployeeNightDifferential($evaluation, $service, $branch_id)
    {
        $all_night_diff = array();
        $worked_hours_2nd = $evaluation["2nd"]["worked_hours"];
        $all_night_diff["service"] = $service;
        $all_night_diff['first'] = array('night_diff' => $evaluation["1st"]["night_diff"], 'amount' => 0);
        $all_night_diff['second'] = array('night_diff' => $evaluation["2nd"]["night_diff"], 'amount' => 0);
        $ot = array();
        if ($evaluation['overtime']) {
            $ot = $evaluation['overtime'];
        }


        $all_night_diff['first']['amount'] = $this->computeNightDifferential($all_night_diff['first']['night_diff'], $service, $branch_id);
        $all_night_diff['second']['amount'] = $this->computeNightDifferential($all_night_diff['second']['night_diff'], $service, $branch_id, $worked_hours_2nd, $ot);
        return $all_night_diff;
    }
    /**
     * Compute Absence
     *
     * @param string $undertime
     * @param int $service
     * @return array
     * @throws \Exception
     */
    public function computeAbsence($absence_hr, $service)
    {
        $absence_amount = round(($absence_hr * $service['service_record']['rate']['hRate']), 2);
        return $absence_amount;
    }
    /**
     * Get Employee Undertime
     *
     * @param float $late
     * @param float $underTime
     * @param string $date
     * @param array $service
     * @return array
     * @throws \Exception
     */
    public function getEmployeeAbsence($service)
    {
        $all_absence = array();
        $all_absence["service"] = $service;
        $all_absence['first'] = array('absent_hour' => 0, 'amount' => 0);
        $all_absence['second'] = array('absent_hour' => 0, 'amount' => 0);
        return $all_absence;
    }

    /**
     * Check Leave For Straight Hour Schedules
     *
     * @param string $employee_id
     * @return array
     * @throws \Exception
     */
    public function checkLeaveForStraightHoursScheds($date, $leave)
    {
        $update = "";
        if ($leave["start_date"] == $leave["end_date"]) {
            if ($leave["start_period"] != "0") {
                if ($leave["end_period"] == "0") {
                    // leave is on first period
                    //no lates
                    $update = "updateLates";
                } else {
                    // leave is on second period
                    //update undertime
                    $update = "updateUndertime";
                }
            }
        } else {
            if ($leave["start_period"] != "0" && $leave["start_date"] == $date) {
                $update = "updateUndertime";
            }
            if ($leave["end_period"] != "0" && $leave["end_date"] == $date) {
                $update = "updateLates";
            }
        }
        return $update;
    }

    /**
     * Set Daily Pay Info array
     *
     * @param string $employee_id
     * @return array
     * @throws \Exception
     */
    public function setDailyPayInfo($employee_id, $date, $department_id, $payroll_id)
    {
        $payroll_daily = array();
        $payroll_daily['payroll_id'] = $payroll_id;
        $payroll_daily['date'] = $date;
        $payroll_daily['employee_id'] = $employee_id;
        $payroll_daily['daily_rate'] = '0';
        $payroll_daily['hourly_rate'] = '0';
        $payroll_daily['total_working_hours'] = '0';
        $payroll_daily['second_period_hours'] = '0';
        $payroll_daily['schedule'] = '';
        $payroll_daily['absent_period'] = '0';
        $payroll_daily['absent_amount'] = '0';
        $payroll_daily['night_differential_hours'] = '0';
        $payroll_daily['night_differential_amount'] = '0';
        $payroll_daily['undertime_minutes'] = '0';
        $payroll_daily['undertime_amount'] = '0';
        $payroll_daily['late_minutes'] = '0';
        $payroll_daily['late_amount'] = '0';
        $payroll_daily['overbreak_minutes'] = '0';
        $payroll_daily['overbreak_amount'] = '0';
        $payroll_daily['has_late_penalty'] = '0';
        $payroll_daily['paid_hours'] = '0';
        $payroll_daily['remarks'] = '';
        $payroll_daily['required_to_work'] = '0';
        $payroll_daily['has_leave'] = '0';
        $payroll_daily['leave_credits'] = '0';
        $payroll_daily['daily_allowance'] = '0';
        $payroll_daily['missing_log'] = '0';
        $payroll_daily['is_suspended'] = '0';
        $payroll_daily['is_holiday'] = '0';
        $payroll_daily['department_id'] = $department_id;
        return $payroll_daily;
    }

    /**
     * Check DTR from prev payroll
     *
     * @param string $employee_id
     * @param string $date
     * @return boolean
     * @throws \Exception
     */
    public function checkDtrPayroll($employee_id, $date)
    {
        $this->db->where("employee_id", $employee_id);
        $this->db->where("date", $date);
        $queryResult = $this->db->get('pr_date_information', null);

        if ($this->db->count > 0) {
            foreach ($queryResult as $pr_date_information) {
                if ($pr_date_information['schedule'] != '' || $pr_date_information['schedule'] != null) {
                    $new_date = new DateTime($date);
                    $new_date->modify('-1 day');
                    return $this->checkDtrPayroll($employee_id, $new_date->format('Y-m-d'));
                } else {
                    if ($pr_date_information['paid_hours'] > 0) {
                        return true;
                    } else {
                        return false;
                    }
                }
            }
        }
    }

    /**
     * get payroll date from prev payroll
     *
     * @param string $employee_id
     * @param string $start
     * @param string $end
     * @return array
     * @throws \Exception
     */
    public function getDtrPayroll($employee_id, $start, $end)
    {
        $payroll_dates = array();
        $this->db->where("employee_id", $employee_id);
        $this->db->where("(date >= '" . $start . "' AND date <= '" . $end . "')");
        $queryResult = $this->db->get('pr_date_information', null);

        if ($this->db->count > 0) {
            foreach ($queryResult as $pr_date_information) {
                $payroll_dates[$pr_date_information["date"]] = $pr_date_information;
            }
        }
        return $payroll_dates;
    }

    /**
     * get unposted leave
     *
     * @param array $leaves
     * @param string $employee_id
     * @return boolean
     * @throws \Exception
     */
    public function getpreviousUnpostedLeave($employee_leaves, $employee_id, $payroll_id, $dtr_getter)
    {
        $unposted_leaves = array();
        $adjustments = array();
        $counter = 0;
        $total_amount = 0;
        $to_update_leaves = array();
        foreach ($employee_leaves as $leaves) {
            if ($leaves["is_paid"] == "1" && $leaves["balance"] <= 0) {
                $begin = new DateTime($leaves["start_date"]);
                $end = new DateTime($leaves["end_date"]);
                $this->db->where("employee_id", $employee_id);
                // $this->db->where("date", $date);
                $payroll_date_info = $this->getDtrPayroll($employee_id, $leaves["start_date"], $leaves["end_date"]);
                $emp_leaves[$employee_id] = array($leaves);
                $credits_used = $leaves["credits_used"];
                $credits_validated = 0;
                for ($i = $begin; $i <= $end; $i->modify('+1 day')) {
                    $date = $i->format('Y-m-d');
                    $amount = 0;
                    if (strtotime($date) < strtotime($this->params['end_date'])) {
                        if (isset($payroll_date_info[$date]) && $payroll_date_info[$date]['schedule'] !== '' && !empty($payroll_date_info[$date]['schedule'])) {
                            // with schedule
                            $schedule = json_decode($payroll_date_info[$date]['schedule']);
                            //find leave for periods
                            $first_period_leave = $dtr_getter->findLeaveInPeriod($date, "0", $employee_id, $emp_leaves);
                            $second_period_leave = $dtr_getter->findLeaveInPeriod($date, "1", $employee_id, $emp_leaves);
                            if ($first_period_leave["leave"]["is_paid"] === "1") {
                                if ($credits_used > 0) {
                                    if (!isset($to_update_leaves[$first_period_leave["leave"]["id"]])) {
                                        $to_update_leaves[$first_period_leave["leave"]["id"]] = array("payroll_id" => $payroll_id, "amount" => 0, "from_previous_cutoff" => "1", "credits" => 0, "leave_record_id" => $first_period_leave["leave"]["id"]);
                                    }
                                    $amount += $payroll_date_info[$date]['first_period_hours'] * $payroll_date_info[$date]['hourly_rate'];
                                    $dif = $payroll_date_info[$date]['first_period_hours'] / $payroll_date_info[$date]['total_working_hours'];
                                    $credits_used -= $dif;
                                    $credits_validated += $dif;
                                    $to_update_leaves[$first_period_leave["leave"]["id"]]["amount"] += $amount;
                                    $to_update_leaves[$first_period_leave["leave"]["id"]]["credits"] += $dif;
                                }
                            }
                            if ($second_period_leave["leave"]["is_paid"] === "1") {
                                if ($credits_used > 0) {
                                    if (!isset($to_update_leaves[$second_period_leave["leave"]["id"]])) {
                                        $to_update_leaves[$second_period_leave["leave"]["id"]] = array("payroll_id" => $payroll_id, "amount" => 0, "from_previous_cutoff" => "1", "credits" => 0, "leave_record_id" => $second_period_leave["leave"]["id"]);
                                    }
                                    $amount += $payroll_date_info[$date]['second_period_hours'] * $payroll_date_info[$date]['hourly_rate'];
                                    $dif = $payroll_date_info[$date]['second_period_hours'] / $payroll_date_info[$date]['total_working_hours'];
                                    $credits_used -= $dif;
                                    $credits_validated += $dif;
                                    $to_update_leaves[$second_period_leave["leave"]["id"]]["amount"] += $amount;
                                    $to_update_leaves[$second_period_leave["leave"]["id"]]["creditsUsed"] += $dif;
                                }
                            }
                            if ($amount > 0) {
                                $total_amount += $amount;
                                $prev_leaves = array("info" => $leaves, "amount" => $amount, "credits_used" => $credits_used, "credits_validated" => $credits_validated);
                                $prev_leaves["adjustment"] = array(
                                    "payroll_id" => $payroll_id,
                                    "adjustment_id" => 0,
                                    "amount" => $amount,
                                    "employee_id" => $employee_id,
                                    "posted" => "1",
                                    "type" => "2",
                                    "remarks" => "Unpaid leave from previous cut off with " . $credits_validated . " paid day/s",
                                );
                                array_push($unposted_leaves, $prev_leaves);
                                array_push($adjustments, $prev_leaves["adjustment"]);
                            }
                        }
                    }
                }
            }
        }
        $this->db->insertMulti('pr_adjustments', $adjustments);

        /**
         * Logger
         */
        if ($this->db->getLastErrno() !== 0) {
            $this->Logger->LogError("[ERROR] | InsertMulti [Payroll Generation] On Line : " . __LINE__, $this->db->getLastError());
        }

        return array("unposted_leaves" => $unposted_leaves, "total_amount" => $total_amount, "adjustments" => $adjustments, "leaves" => $to_update_leaves);
    }
}
