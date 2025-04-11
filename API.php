<?php


namespace Resources\JuanHR\Modules\AdmsReceiver;

use Core\Helpers\Notification;
use Core\System\Main as Core;
use Core\Security\JWToken;
use Core\Modules\ModuleInterface;
use Resources\JuanHR\System\Main;
use DateTimeImmutable;
use Exception;

/**
 * Class Authenticate
 * @package Core\Modules
 */
class API extends Main implements ModuleInterface
{
    public const GET_PERMISSION_ALIAS = null;

    public const POST_PERMISSION_ALIAS = null;

    public const PUT_PERMISSION_ALIAS = null;

    public const DELETE_PERMISSION_ALIAS = null;

    /**
     * Table name
     *
     * @var string
     */
    protected string $table;

    /**
     * Accepted parameters
     *
     * @var array|string[]
     */
    protected array $accepted_parameters;

    /**
     * Response column
     *
     * @var array|string[]
     */
    protected array $response_column;

    private Notification $Notification;

    private Core $Core;

    /**
     * Authenticate constructor.
     */
    public function __construct()
    {
        $this->table = 'adms_command';

        $this->accepted_parameters = [];

        $this->response_column = [];

        $this->Notification = new Notification();

        $this->Core = new Core();

        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    public function httpGet(array $payload, bool $api = true)
    {
        $this->Logger->logDebug("SERVER". __LINE__, [$_SERVER]);
        try {
            if ($_SERVER["REDIRECT_URL"]) {
                $exploded_request_url = array_values(explode("/", $_SERVER["REDIRECT_URL"]));
                $this->Logger->logDebug("exploded_request_url". __LINE__, [$exploded_request_url]);
                // request for getting the commands from server
                if (end($exploded_request_url) === 'getrequest') {
                    $this->db->where('device_serial_number', $_GET['SN']);
                    $to_create_commands = $this->db->get($this->table);
                    $this->Logger->logDebug("to_create_commands ADMS API". __LINE__, [$to_create_commands]);
                    if ($this->db->getLastErrno() > 0) {
                        // Log error
                        $this->Logger->logError('Failed to get ADMS command', [$this->db->getLastError()]);
                        exit('OK');
                    }

                    // check if has data
                    $returned_commands = null;
                    if (is_array($to_create_commands) && count($to_create_commands)) {
                        foreach ($to_create_commands as $datas_command) {
                            $command = null;

                            switch ($datas_command["command_type"]) {
                                case 0:
                                    // Command if Adding or Modifying new User to Device
                                    $command = 'C:' . $datas_command['id'] . ':DATA UPDATE USERINFO PIN=' . $datas_command["user_id"] . '	Name=' . $datas_command["full_name"] . '	Pri=' . $datas_command["privilage"];
                                    break;
                                case 1:
                                    // Command if Enrolling FingerPrint
                                    $command = 'C:' . $datas_command['id'] . ':ENROLL_FP PIN=' . $datas_command["user_id"] . '	FID=6';
                                    break;
                                case 2:
                                    // Command if Enrolling Face
                                    // $command = 'C:'.$datas_command['id'].':ENROLL_BIO TYPE=2	PIN=1';
                                    // $command = 'C:'.$datas_command['id'].':DATA ENROLL_BIO TYPE=1	PIN='.$datas_command["user_id"];
                                    break;
                                case 3:
                                    // Command for deleting specific user info
                                    $command = 'C:' . $datas_command['id'] . ':DATA DELETE USERINFO PIN=' . $datas_command["user_id"];
                                    break;
                                case 4:
                                    // Command for deleting specific user info
                                    $command = 'C:' . $datas_command['id'] . ':DATA DELETE USERINFO';
                                    break;
                                case 5:
                                    // Command for Getting Attendance Record
                                    $command = 'C:' . $datas_command['id'] . ':DATA QUERY ATTLOG StartTime=' . $datas_command["start_date_time"] . '	EndTime=' . $datas_command["end_date_time"];
                                    break;
                                case 6:
                                    // Command if Enrolling Palm
                                    // $command = 'C:'.$datas_command['id'].':ENROLL_BIO TYPE=2	PIN=1';
                                    // $command = 'C:'.$datas_command['id'].':DATA ENROLL_BIO TYPE=1	PIN='.$datas_command["user_id"];
                                    break;
                                case 7:
                                    // Command for uploading biodata template
                                    $this->db->where('juanhr_employee_id', $datas_command["juanhr_emp_id"]);
                                    $emp_template_info = $this->db->get('device_user_info');

                                    // Check if has data for bio
                                    if ($emp_template_info) {
                                        foreach ($emp_template_info as $template_value) {
                                            $command = null;
                                            if ($template_value['type_of_template'] === 1) {
                                                //for biodata/unified Template
                                                $command = 'C:' . $datas_command["id"] . ':DATA UPDATE BIODATA Pin=' . $datas_command["user_id"] . '	No=' . $template_value["number"] . '	Index=' . $template_value["indexes"] . '	Valid=' . $template_value["valid"] . '	Duress=' . $template_value["duress"] . '	Type=' . $template_value["type"] . '	MajorVer=' . $template_value["majorver"] . '	MinorVer=' . $template_value["minorver"] . '	Format=' . $template_value["format"] . '	Tmp=' . $template_value["tmp"];
                                            } elseif ($template_value['type_of_template'] === 2) {
                                                //for fingerprint template
                                                $command = 'C:' . $datas_command["id"] . ':DATA UPDATE FINGERTMP PIN=' . $datas_command["user_id"] . '	FID=' . $template_value["indexes"] . '	Size=' . $template_value["data_size"] . '	Valid=' . $template_value["valid"] . '	TMP=' . $template_value["tmp"];
                                            } elseif ($template_value['type_of_template'] === 3) {
                                                //for face template
                                                $command = 'C:' . $datas_command["id"] . ':DATA UPDATE FACE PIN=' . $datas_command["user_id"] . '	FID=' . $template_value["indexes"] . '	Valid=' . $template_value["valid"] . '	Size=' . $template_value["data_size"] . '	TMP=' . $template_value["tmp"];
                                            }


                                            // add to variable that will response to device
                                            if ($command) {
                                                if ($returned_commands === null) {
                                                    $returned_commands = $command;
                                                } else {
                                                    $returned_commands .= "\n" . $command;
                                                }
                                            }
                                        }
                                    }
                                    break;
                                default:
                                    break;
                            }

                            // add to variable that will response to device
                            if ($datas_command["command_type"] !== 7) {
                                if ($returned_commands === null) {
                                    $returned_commands = $command;
                                } else {
                                    $returned_commands .= "\n" . $command;
                                }
                            }
                        }
                        if ($returned_commands) {
                            $this->Logger->logDebug("Commands ADMS". __LINE__, [$returned_commands]);
                            exit($returned_commands);
                        }
                    }
                }
                exit('OK');
            }
            // DO nothing, just exit, bye
            exit('OK');
        } catch (\Throwable $th) {
            //throw $th;
            //throw $th;
            $this->Logger->logError('Get catch error', [$th]);
            exit('OK');
        }
    }

    /**
     * @inheritDoc
     */
    public function httpPost(array $payload)
    {
        $this->Logger->logDebug("httpPost _GET". __LINE__, [$_GET]);
        $this->Logger->logDebug("ADMS Data" . __LINE__, [file_get_contents('php://input')]);
        try {
            if ($_GET) {
                // Explode Request URL
                $exploded_request_url = array_values(explode("/", $_SERVER["REDIRECT_URL"]));
                // Check if has serial number
                $adms_data = file_get_contents('php://input');

                if (isset($_GET['SN'])) {
                    $serial_number = $_GET['SN'];

                    // Get Data of Device from JuanHR
                    $this->db->where('device_serial_id', $serial_number);
                    $this->db->where('is_active', 1);
                    $biometrics_data = $this->db->getOne('biometrics');

                    // Check if has data of Device in JuanHR
                    if (!$biometrics_data) {
                        $this->Logger->logDebug("No Device Data" . __LINE__, [$_GET]);
                        exit('OK');
                    }
                    $this->Logger->logDebug("Initiate Device " . __LINE__, [$_GET]);
                    $this->Logger->logDebug("Device Data" . __LINE__, [$biometrics_data]);
                    if (array_key_exists('table', $_GET)) {
                        // Split the data into individual elements
                        // $parts = preg_split('/\s+/', $adms_data);
                        // $result = [];

                        // // Group elements into sub-arrays
                        // for ($i = 0; $i < count($parts); $i += 9) {
                        //     $result[] = implode(' ', array_slice($parts, $i, 9));
                        // }

                        // // check if no data to be process
                        // if (!$result) {
                        //     exit('OK');
                        // }

                        // Split by new lines first
                        $result = explode("\n", trim($adms_data));

                        $this->Logger->logDebug("Result" . __LINE__, [$result]);
                        foreach ($result as $value) {
                            if (!$value) {
                                continue;
                            }
                            // Split the Data into Columns
                            $columns = preg_split('/\s+/', trim($value));
                            if ($_GET['table'] == 'BIODATA') {
                                // This part of code is to check if the post request is for BIODATA
                                // BIODATA or UNIFIED TEMPLATE contain data of fp, face and palm.
                                // Suitable for PROFACE model
                                $biodata_columns = preg_split('/\s+/', trim($value));

                                if (!$biodata_columns) {
                                    $this->Logger->logDebug("No Biodata Columns" . __LINE__, [$value]);
                                    continue;
                                }

                                // Check if has PIN
                                if (count($biodata_columns) !== 11) {
                                    $this->Logger->logDebug("No Biodata Columns not 11 count" . __LINE__, $biodata_columns);
                                    continue;
                                }

                                // populates an array with the key-value pairs
                                parse_str($biodata_columns[1], $pin_columns);
                                parse_str($biodata_columns[2], $no_columns);
                                parse_str($biodata_columns[3], $index_columns);
                                parse_str($biodata_columns[4], $valid_columns);
                                parse_str($biodata_columns[5], $duress_columns);
                                parse_str($biodata_columns[6], $type_columns);
                                parse_str($biodata_columns[7], $majorver_columns);
                                parse_str($biodata_columns[8], $minorver_columns);
                                parse_str($biodata_columns[9], $format);
                                $template = trim($biodata_columns[10], "Tmp=");

                                // Check if has needed data
                                if (!$pin_columns || !$no_columns || !$index_columns || !$valid_columns || !$duress_columns || !$type_columns || !$majorver_columns || !$minorver_columns || !$template) {
                                    $this->Logger->logDebug("Kulang ng Data" . __LINE__, $biodata_columns);
                                    continue;
                                }

                                // Get employee id from juanHR
                                $this->db->where('emp_user_id', $pin_columns['Pin']);
                                $this->db->where('device_id', $biometrics_data['device_id']);
                                $employee_device_data = $this->db->getOne('emp_biometrics_registration');

                                // Check if has data
                                if (!$employee_device_data) {
                                    $this->Logger->logDebug("Kulang ng Data" . __LINE__, $biodata_columns);
                                    continue;
                                }

                                // Process to check if has existing data
                                if ($type_columns['Type'] === '8') {
                                    $this->db->where('indexes', $index_columns['Index']);
                                }
                                $this->db->where('juanhr_employee_id', $employee_device_data['employee_id']);
                                $this->db->where('type', $type_columns['Type']);
                                $this->db->where('indexes', $index_columns['Index']);
                                $existing_data = $this->db->get('device_user_info');

                                // delete existing
                                if ($existing_data) {
                                    foreach ($existing_data as $exist_value) {
                                        $this->db->where('id', $exist_value['id']);
                                        $this->db->delete('device_user_info');
                                    }
                                }

                                // Parameter to insert or delete
                                $data_to_insert_device_user_info = array(
                                    'number' => $no_columns['No'],
                                    'indexes' => $index_columns['Index'],
                                    'valid' => $valid_columns['Valid'],
                                    'duress' => $duress_columns['Duress'],
                                    'majorver' => $majorver_columns['MajorVer'],
                                    'minorver' => $minorver_columns['MinorVer'],
                                    'format' => $format['Format'],
                                    'tmp' => $template,
                                    'type' => $type_columns['Type'],
                                    'device_serial_id' => $serial_number,
                                    'user_id' => $pin_columns['Pin'],
                                    'juanhr_employee_id' => $employee_device_data['employee_id'],
                                    'type_of_template' => 1
                                );

                                //execute insert query
                                $data_to_insert_device_user_info['id'] = $this->db->insert('device_user_info', $data_to_insert_device_user_info);

                                //check if has error upon insert
                                if ($this->db->getLastErrno() > 0) {
                                    // Log error
                                    $this->Logger->logError('Failed to insert device_user_info', [$this->db->getLastError()]);
                                    continue;
                                }

                                $this->Logger->logDebug("Inserted Data on User info" . __LINE__, [$data_to_insert_device_user_info]);
                            } elseif ($_GET['table'] == 'ATTLOG') {
                                // This part of code is to check if the post request is for real time attendance data
                                $this->Logger->logDebug("ATTLOG " . __LINE__, [$_GET]);
                                // For Real Time attendance
                                // populate data
                                $this->Logger->logDebug("ATTLOG Data " . __LINE__, $columns);
                                $label = ['user_id', 'date', 'time', 'log_type'];
                                $filtered_columns = array_slice($columns, 0, 4);

                                // Check filtered columns is correct
                                if (count($filtered_columns) !== 4) {
                                    $this->Logger->logDebug("Filtered Columns " . __LINE__, $filtered_columns);
                                    continue;
                                }

                                $labeled_data = array_combine($label, $filtered_columns);
                                $this->Logger->logDebug("Labeled Data " . __LINE__, $labeled_data);
                                // Check Log Type
                                if (strlen($labeled_data["log_type"])  > 1) {
                                    $this->Logger->logError("invalid Attendance Log Type" . __LINE__, $labeled_data);
                                    continue;
                                }

                                //check if has valid user id
                                if (preg_match('/[a-zA-Z]/', $labeled_data["user_id"])) {
                                    $this->Logger->logError("Invalid Attendance User id" . __LINE__, $labeled_data);
                                    continue;
                                }

                                // check if the date is valid
                                if (!$this->isValidDate($labeled_data['date'])) {
                                    $this->Logger->logError("Invalid Attendance Date Value" . __LINE__, $labeled_data);
                                    continue;
                                }

                                // check if the time is valid
                                if (!$this->isValidTime($labeled_data['time'])) {
                                    $this->Logger->logError("Invalid Attendance Time Value" . __LINE__, $labeled_data);
                                    continue;
                                }

                                // Get employee id from juanHR
                                $this->db->where('emp_user_id', $labeled_data['user_id']);
                                $this->db->where('device_id', $biometrics_data['device_id']);
                                $employee_device_data = $this->db->getOne('emp_biometrics_registration');

                                if ($this->db->getLastErrno() > 0) {
                                    // Log error
                                    $this->Logger->logError('Failed to get emp_biometrics_registration', [$this->db->getLastError()]);
                                    continue;
                                }

                                // check if has employee data
                                if (!$employee_device_data) {
                                    $this->Logger->logError("NO Employee Device Data" . __LINE__, $employee_device_data);
                                    continue;
                                }
                                $this->Logger->logDebug("Employee Device Data" . __LINE__, $employee_device_data);

                                // Checking if have duplicated data, will avoid this
                                $this->db->where("date", $labeled_data['date']);
                                $this->db->where("time", $labeled_data['time']);
                                $this->db->where("employee_id", $employee_device_data['employee_id']);
                                $this->db->where("log_type", $labeled_data['log_type']);
                                $dtr = $this->db->get('emp_dtr');

                                // Check if has duplicated data, next data if has
                                if ($dtr) {
                                    $this->Logger->logDebug("Duplicated Data" . __LINE__, [$dtr]);
                                    continue;
                                }

                                // Process to insertion, build data to be inserted
                                $data_to_insert = array(
                                    'employee_id' => $employee_device_data['employee_id'],
                                    'date' => $labeled_data['date'],
                                    'time' => $labeled_data['time'],
                                    'log_type' => $labeled_data['log_type'],
                                );

                                //execute insert query
                                $this->Logger->logDebug("Data to Insert " . __LINE__, [$data_to_insert]);
                                $data_to_insert['id'] = $this->db->insert('emp_dtr', $data_to_insert);

                                if (!empty($data_to_insert)) {
                                    $this->db->where('employee_id', $data_to_insert['employee_id']);
                                    $account_id = $this->db->getValue('emp_information', 'account_id');
                                    if (!empty($account_id)) {
                                        $this->Core->db->where('unique_identifier', 'JUANHR');
                                        $system_id = $this->Core->db->getValue('systems', 'id');

                                        $this->pushNotifications($data_to_insert, $account_id, $system_id);
                                    }

                                    $this->Logger->logDebug("Inserted Data" . __LINE__, [$data_to_insert]);
                                }

                                //check if has error upon insert
                                if ($this->db->getLastErrno() > 0) {
                                    // Log error
                                    $this->Logger->logError('Failed to insert DTR', [$this->db->getLastError()]);
                                    continue;
                                }
                            } elseif ($_GET['table'] == 'OPERLOG') {
                                // This part of code is to record the movement happened in Menu of Device
                                // Set Current Datetime
                                $current_datetime = date('Y-m-d H:i:s');

                                if ($columns[0] === 'OPLOG') {
                                    if ($columns[1] === "103") {
                                        // if device delete a user from device]

                                        // Get Employee Biometrics data, purpose is to get the employee id and yun ang isasave as user id
                                        $this->db->where('emp_user_id', intval($columns[5]));
                                        $this->db->where('device_id', $biometrics_data['device_id']);
                                        $emp_bio = $this->db->getOne('emp_biometrics_registration');

                                        // Check if has data
                                        if ($emp_bio) {
                                            // Get Employee Information
                                            $this->db->where('employee_id', $emp_bio['employee_id']);
                                            $emp_info = $this->db->getOne('emp_information');

                                            // check if has data
                                            if ($emp_info) {
                                                $data_to_insert = array('log_type' => $columns[1], 'device_serial_number' => $serial_number, 'logs_date' => $current_datetime, 'user_id' => $emp_info['employee_id']);
                                            }
                                        }

                                        // Delete data from Employee Biometrics Record
                                        $this->db->where('emp_user_id', $columns[5]);
                                        $this->db->where('device_id', $biometrics_data['device_id']);
                                        $this->db->delete('emp_biometrics_registration');

                                    } else {
                                        $data_to_insert = array('log_type' =>$columns[1], 'device_serial_number' => $serial_number, 'logs_date' => $current_datetime);
                                    }

                                } elseif ($columns[0] === 'USER') {

                                    $userid = substr($columns[1], 4);
                                    $priv = substr($columns[4], 4);

                                    $this->db->where('employee_id', $userid);
                                    $this->db->where('device_id', $biometrics_data['device_id']);
                                    $emp_bio = $this->db->getOne('emp_biometrics_registration');

                                    if ($emp_bio) {

                                        $this->db->where('id', $emp_bio["id"]);
                                        $this->db->update('emp_biometrics_registration', ['privilege' => $priv]);

                                        //check if has error upon update
                                        if ($this->db->getLastErrno() > 0) {
                                            // Log error
                                            $this->Logger->logError('Failed to Update emp_biometrics_registration', [$this->db->getLastError()]);
                                            continue;
                                        }
                                    }
                                    continue;
                                } elseif ($columns[0] === 'FP' || $columns[0] === 'FACE') {
                                    // This part of code is when the user register his/her face or fingerprint
                                    // when the user enroll fp and face, data will be save
                                    // purpose is when the user added to other device, no need to re-register his/her fp and face]
                                    // Suitable for MB's model

                                    // Get needed Data
                                    $userid = substr($columns[1], 4);
                                    $fid = substr($columns[2], 4);
                                    $size = substr($columns[3], 5);
                                    $valid = substr($columns[4], 6);
                                    $tmp = substr($columns[5], 4);
                                    $type_of_template = $columns[0] === 'FP' ? 2  : 3;

                                    // Get employee id from juanHR
                                    $this->db->where('emp_user_id', $userid);
                                    $this->db->where('device_id', $biometrics_data['device_id']);
                                    $employee_device_data = $this->db->getOne('emp_biometrics_registration');

                                    // Check if has data
                                    if (!$employee_device_data) {
                                        $this->Logger->logDebug("Walang EMployee BIo Data" . __LINE__, [$employee_device_data]);
                                        continue;
                                    }

                                    // Process to check if has existing fa or face data
                                    $this->db->where('juanhr_employee_id', $employee_device_data['employee_id']);
                                    $this->db->where('indexes', $fid);
                                    $this->db->where('type_of_template', $type_of_template);
                                    $existing_data = $this->db->get('device_user_info');

                                    // delete existing
                                    if ($existing_data) {
                                        foreach ($existing_data as $exist_value) {
                                            $this->db->where('id', $exist_value['id']);
                                            $this->db->delete('device_user_info');
                                        }
                                    }

                                    // Add FP or Face Data to DB
                                    $device_user_info = array(
                                        'type' =>  $columns[0] === 'FP' ? 1 : 2,
                                        'device_serial_id' => $serial_number,
                                        'user_id' => $userid,
                                        'juanhr_employee_id' => $employee_device_data['employee_id'],
                                        'indexes' => $fid,
                                        'valid' => $valid,
                                        'tmp' =>  $tmp,
                                        'data_size' => $size,
                                        'type_of_template' => $type_of_template
                                    );

                                    //execute insert query
                                    $data = $this->db->insert('device_user_info', $device_user_info);

                                    $this->Logger->logDebug("Inserted Data on User FP info" . __LINE__, $data);

                                    //check if has error upon insert
                                    if ($this->db->getLastErrno() > 0) {
                                        // Log error
                                        $this->Logger->logError('Error on inserting device_user_info', [$this->db->getLastError()]);
                                        continue;
                                    }

                                    // Create a data to be added on device logs
                                    $data_to_insert = array('log_type' => $columns[0] === 'FP' ? 6 : 69, 'device_serial_number' => $serial_number, 'user_id' => $userid, 'logs_date' => $current_datetime);
                                }
                                // insert logs
                                if ($data_to_insert) {
                                    //execute insert query
                                    $this->db->insert('device_biometrics_logs', $data_to_insert);

                                    //check if has error upon insert
                                    if ($this->db->getLastErrno() > 0) {
                                        // Log error
                                        $this->Logger->logError('Error on inserting device_biometrics_logs', [$this->db->getLastError()]);
                                        continue;
                                    }
                                }
                            } else {
                                continue;
                            }
                        }
                        exit('OK');
                    } elseif (end($exploded_request_url) === 'devicecmd') {
                        // this part of condition if for the response of device from the command executed

                        // handling response data from device
                        $lines = explode("\n", $adms_data);
                        $data = array();

                        // Arrange Data by command
                        foreach ($lines as $line) {
                            parse_str($line, $parsedLine);
                            $data[] = $parsedLine;
                        }

                        // check if has data
                        if (count($data)) {
                            foreach ($data as $key => $data_res) {
                                if (count($data_res)) {
                                    // check if the return was 0, which means success
                                    if ($data_res["Return"] === "0") {
                                        // get the data from adms command db
                                        $this->db->where('id', $data_res["ID"]);
                                        $this->db->where('device_serial_number', $_GET['SN']);
                                        $command_existing_data = $this->db->getOne($this->table);

                                        // Check if query has error
                                        if ($this->db->getLastErrno() > 0) {
                                            // Log error
                                            $this->Logger->logError('Error on inserting device_user_info', [$this->db->getLastError()]);
                                            exit('OK');
                                        }

                                        // Check if has data
                                        if ($command_existing_data) {
                                            $data_to_update = array();
                                            if ($command_existing_data['command_type'] === 1) {
                                                // for update of fingerprint register flag
                                                $data_to_update = array('is_fingerprint_registered' => 1,);
                                            } elseif ($command_existing_data['command_type'] === 2) {
                                                // for update of face register flag
                                                $data_to_update = array('is_facial_registered' => 1,);
                                            } elseif ($command_existing_data['command_type'] === 0) {
                                                // for added or modify user
                                                // Add Command to register existing face, fp and biodata/unified template
                                                $this->db->where('emp_user_id', $command_existing_data['user_id']);
                                                $this->db->where('device_id', $biometrics_data['device_id']);
                                                $employee_biometric_data = $this->db->getOne('emp_biometrics_registration');

                                                // check if has employee biometrics data
                                                if ($employee_biometric_data) {
                                                    // Check if has data to be upload
                                                    $this->db->where('juanhr_employee_id', $employee_biometric_data['employee_id']);
                                                    $data_to_upload = $this->db->get('device_user_info');

                                                    // Check if has data to be upload, if had, add command
                                                    if ($data_to_upload) {
                                                        $data_to_insert = array('device_serial_number' => $serial_number, 'user_id' => $command_existing_data['user_id'], 'command_type' => 7, 'juanhr_emp_id' => $employee_biometric_data['employee_id']);

                                                        //execute insert query
                                                        $data = $this->db->insert($this->table, $data_to_insert);
                                                    }
                                                }
                                            } elseif ($command_existing_data['command_type'] === 7) {
                                                // Update fp and face registration status
                                                $this->db->where('juanhr_employee_id', $command_existing_data["juanhr_emp_id"]);
                                                $emp_template_info = $this->db->get('device_user_info');

                                                if ($emp_template_info) {
                                                    $fp_data = array_filter(
                                                        $emp_template_info,
                                                        fn ($temp_info) => $temp_info['type'] == '1'
                                                    );
                                                    $face_data = array_filter(
                                                        $emp_template_info,
                                                        fn ($temp_info) => $temp_info['type'] == '2' || $temp_info['type'] == '9'
                                                    );


                                                    $data_to_update = array('is_facial_registered' => count($face_data) ? 1 : 0, 'is_fingerprint_registered' => count($fp_data) ? 1 : 0);
                                                }
                                            }

                                            // check if has data to update
                                            if (count($data_to_update)) {
                                                $this->db->where('emp_user_id', $command_existing_data['user_id']);
                                                $this->db->where('device_id', $biometrics_data['device_id']);

                                                // Execute update query
                                                $this->db->update('emp_biometrics_registration', $data_to_update);

                                                // Check if query has error
                                                if ($this->db->getLastErrno() > 0) {
                                                    // Log error
                                                    $this->Logger->logError('Error on updating emp_biometrics_registration', [$this->db->getLastError()]);
                                                    exit('OK');
                                                }
                                            }

                                            // Delete data if success
                                            // Execute Delete query
                                            $this->db->where('id', $data_res["ID"]);
                                            $this->db->where('device_serial_number', $_GET['SN']);
                                            $this->db->delete($this->table);

                                            // Check if success on deletion
                                            if ($this->db->getLastErrno() > 0) {
                                                // Log error
                                                $this->Logger->logError('Error on deleting adms command in database', [$this->db->getLastError()]);
                                                exit('OK');
                                            }
                                        }
                                        exit('OK');
                                    } else {
                                        $this->Logger->logError('returned data was not success', [$data_res]);
                                        exit('OK');
                                    }
                                }
                            }
                        }
                        exit('OK');
                    }
                }
            } else {
                $this->Logger->logDebug("No GET data". __LINE__, [$_GET]);
            }
            exit('OK');
        } catch (\Throwable $th) {
            //throw $th;
            $this->Logger->logError('Post catch error', [$th]);
            exit('OK');
        }
    }

    /**
     * @inheritDoc
     */
    public function httpPut(int $identity, array $payload)
    {
        // SILENCE IS GOLDEN
        $this->Logger->logError('httpPut ADMS API');
        exit('OK');
        // return $this->Messages->jsonErrorRequestMethodNotServed();
    }

    /**
     * @inheritDoc
     */
    public function httpDel($identity, array $payload)
    {
        // SILENCE IS GOLDEN
        $this->Logger->logError('httpPut ADMS API');
        exit('OK');
        // return $this->Messages->jsonErrorRequestMethodNotServed();
    }

    /**
     * @inheritDoc
     */
    public function httpFileUpload(int $identity, array $payload)
    {
        // SILENCE IS GOLDEN
        $this->Logger->logError('httpPut ADMS API');
        exit('OK');
        // return $this->Messages->jsonErrorRequestMethodNotServed();
    }

    public function isValidDate($date)
    {
        $parts = explode('-', $date);

        // Ensure the date has exactly three parts: year, month, and day
        if (count($parts) !== 3) {
            return false;
        }

        list($year, $month, $day) = $parts;

        // Check if the provided year, month, and day form a valid date
        return checkdate((int)$month, (int)$day, (int)$year);
    }

    public function isValidTime($time)
    {
        $parts = explode(':', $time);

        // Ensure the time has exactly three parts: hour, minute, and second
        if (count($parts) !== 3) {
            return false;
        }

        list($hour, $minute, $second) = $parts;

        // Check if the hour, minute, and second are within valid ranges
        return (int)$hour >= 0 && (int)$hour < 24 &&
            (int)$minute >= 0 && (int)$minute < 60 &&
            (int)$second >= 0 && (int)$second < 60;
    }

    /**
     * @param array $log
     * @param int $account_id
     * @param int $system_id
     *
     * @return void
     */
    private function pushNotifications($log, $account_id, $system_id)
    {
        $log_type = $log['log_type'];
        $time = $log['time'];

        $log_types = ['Clocked In', 'Clocked Out'];
        $formatted_time = date('g:i A', strtotime($time));

        $message = "You've successfuly {$log_types[$log_type]} at $formatted_time.";
        if ($this->Notification->createNotification([$account_id], $message, '/juanHR/dtr', $system_id)) {
            $title = 'JuanHR Mobile';
            $this->Notification->sendMobileNotification([$account_id], $title, $message);
        }
    }
}
