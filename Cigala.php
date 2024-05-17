<?php

/**
 * @abstract This main class controls the data flow process (from ftp server to DBMS)
 * @author Bruno C. Vani (brunovani22@gmail.com) 
 * 
 */
class Cigala
{

    //dbms config.    
    private $db_host; //primary host 
    private $db_user;
    private $db_password;
    private $db_user2;
    private $db_password2;
    
    private $db_name;
    private $db_port;
    public  $db_conn;
    
    //ftp  config.
    protected $ftp_host = "200.145.185.149";
    protected $ftp_user = "cigala_ftp";
    protected $ftp_password = "";
    public    $ftp_conn;
    //paths 
    public $week_path = "/var/www/is/htdocs/view/weekly";
    public $full_fonts_path = "/var/www/is/fonts/";
    //public $full_fonts_path="c:/ms4w/apps/cigala/fonts/";    
    public $full_rinex_path = "tmp/";
    public $full_rinex_path2 = "/var/www/is/htdocs/ismrtool/rinex/tmp2/";
    public $root_path = "/var/www/is/htdocs/";
    //public $unzip_="c://"Arquivos de Programas/"/winrar/winrar ";    
    public $full_gifs_path = "";
    public $gifs_path = "";
    public $full_retrieval_path = "/var/www/is/htdocs/ismrtool/retrieval/tmp/";
    public $full_image_path = "/var/www/is/htdocs/ismrtool/view/tmp/";
    public $full_grids_path = "/var/www/is/htdocs/ismrtool/grid/tmp/";
    public $full_maps_path = "/var/www/is/htdocs/ismrtool/map/tmp/";
    // public $full_log_path="/var/www/is/htdocs//import//";   
    public $log_path = "log/";
    public $full_logs_path = "/var/www/is/htdocs/ismrtool/import/log/";
    //attributes of the class
    private $station_id;
    private $name;
    private $year;
    private $day;
    private $file;

    /**
     * Setup access to PostgreSQL Database
     */
    public function db_setup()
    {
        $this->db_host = getenv('DB_HOST');
        $this->db_user = getenv('DB_USER');
        $this->db_password = getenv('DB_PASSWORD');
        $this->db_name = getenv('DB_NAME');
        $this->db_port = getenv('DB_PORT');

        $this->db_user2 = getenv('DB_USER2');
        $this->db_password2 = getenv('DB_PASSWORD2');
    }

    /**
     * Setup database from JSON local file
     */
    public function db_setup_file($filename)
    {
        $d = json_decode(file_get_contents($filename),true);        
        $this->db_host = $d["host"];
        $this->db_user = $d["user"];
        $this->db_password = $d["password"];
        $this->db_name = $d["name"];
        $this->db_port = $d["port"];
        $this->db_user2 = $d["user2"];
        $this->db_password2 = $d["password2"];
    }


    /**
     * Returns an array with the column names of ISMR table on database, except the columns on $sub array.
     * Only works if at least one row was inserted earlier.
     * @param Array $sub Array with columns that not might be returned.
     * @param String $table Table or View to retrieve on DBMS (default: ISMR)
     * @return Array An array with the column names.
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function getISMRFields($sub, $table = "ismr")
    {

        //set the ismr raw table or the arguments table        
        $sql = "select * from $table limit 1";
        //select column_name from information_schema.columns where table_name = 'ismr';
        $rs = pg_query($sql);

        if (pg_num_rows($rs)) {
            $aux = pg_fetch_assoc($rs);
        }

        //no columns restrictions
        if (!$sub)
            foreach ($aux as $key => $value) {
                $ret[] = $key;
            }

        //not display columns on sub array
        else
            foreach ($aux as $key => $value) {
                if (!in_array($key, $sub))
                    $ret[] = $key;
            }

        return $ret;
    }

    /* Select the proper database table or view automaticcaly according to 
     * the desired fields to analyze.
     * @return string A string with the proper view name 
     * @author Bruno C. Vani (brunovani22@gmail.com) 
     */

    function getViewName($field, $field2 = null)
    {
        if (!$field2)
            $field2 = "";

        $views = "ismr_views";

        //check - views for solution
        $solution = array("x", "y", "z", "sigmaz", "sigmay", "sigmaz", "error3d", "sigma3d");
        if (in_array($field, $solution) || in_array($field2, $solution)) {
            $station = true;
            $views .= "_station_solution_inv";
        }

        // check - views for meta_s4_class 
        if (
            preg_match('/^class/', $field) || (preg_match('/_tracked$/', $field)) ||
            preg_match('/^class/', $field2) || (preg_match('/_tracked$/', $field2))
        ) {
            $views .= "_station_meta";
        }

        // check  - views for variances (used only with ismr_views)
        $var_fields = array(
            "var_ca_au", "var_p2_au", "var_l1_au", "var_l2_au",
            "a1", "u1", "a2", "u2", "prob1_au", "prob2_au",
            "var_ca_conker", "var_p2_conker", "var_l1_conker", "var_l2_conker", "prob1_naka", "prob2_naka",
            "tau1", "tau2", "l1_loss", "l2_loss", "l5_loss",
            "sdiff_l1", "sdiff_l2", "azim_c", "elev_c", "s4_c", "s4_l2_c", "phi60l1_c", "tec_15_c",
            "tec_30_c", "tec_45_c", "tec_c", "l1_locktime_c", "l2_e5a_locktime_c", "f2nd_tec_locktime_c",
            "wn_c", "tow_c", "rate"
        );
        if (in_array($field, $var_fields) || in_array($field2, $var_fields)) {
            $views .= "_station_var";
        }

        return $views;
    }

    /**
     * Return a legend to be included on scatterplots provided by the tool
     * @param integer $svid SVID indentification number of the satellite
     * @param boolean $const Flag to determine when to associate the SVID number with the GNSS owner
     * @return string
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    public function getLegend($svid, $const = true)
    {
        if ($const) {
            $gps = "GPS ";
            $glonass = "GLONASS ";
            $galileo = "Galileo ";
            $sbas = "SBAS";
            $beidou = "Beidou/Comp ";
            $qzss = "QZSS ";
            $default = "SVID";
        } else {
            $gps = $glonass = $galileo = $sbas = $beidou = $qzss = $default = "";
        }
        if ($svid <= 37) {
            $leg = $gps . $svid;
        } else if ($svid >= 38 && $svid <= 61) {
            $svid -= 37;
            $leg = $glonass . $svid;
        } else if ($svid >= 71 && $svid <= 102) {
            $svid -= 70;
            $leg = $galileo . $svid;
        } else if ($svid >= 120 && $svid <= 140) {
            $leg = $sbas . $svid;
        } else if ($svid >= 141 && $svid <= 172) {
            $svid -= 140;
            $leg = $beidou . $svid;
        } else if ($svid >= 181 && $svid <= 187) {
            $svid -= 180;
            $leg = $qzss . $svid;
        } else {
            $leg = $default . $svid;
        }

        return $leg;
    }

    /**
     * Provide statistical metrics about the number of queries provided by the tool.
     * @param String $mode One of the available modes of the tool, such as plot or map
     * @param String $version Version of the tool. Currently "is" (latest), "3.0" or "2.0"
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    public function getUsage($mode, $version = "is", $user_prefix = null, $display = true)
    {
        if ($user_prefix) {
            $grep = "| grep $user_prefix";
        } else {
            $grep = "";
        }
        if ($version == "is") {
            if ($mode == "plot") {
                $out = shell_exec("ls $this->full_image_path {$grep} | wc -l");
            } else if ($mode == "map") {
                $out = shell_exec("ls $this->full_maps_path {$grep} | wc -l");
                $out /= 3;
            } else if ($mode == "grid") {
                $out = shell_exec("ls $this->full_grids_path {$grep}| wc -l");
            } else if ($mode == "retrieval") {
                $out = shell_exec("ls $this->full_retrieval_path {$grep} | wc -l");
            }
            if ($display)
                echo $out;
            else
                return $out;
        } else if ($version == "3.0") { //this version discontinued
            if ($mode == "plot") {
                $out = shell_exec("ls /var/www/cigala/3.0/htdocs/view/tmp | wc -l");
            } else if ($mode == "grid") {
                $out = shell_exec("ls /var/www/cigala/3.0/htdocs/grid/tmp| wc -l");
            }
            if ($display)
                echo $out;
            else
                return $out;
        } else if ($version == "2.0") { //this version is discontinued
            if ($mode == "plot") {
                $out = shell_exec("ls /var/www/cigala/2.0/htdocs/view/tmp | wc -l");
            } else if ($mode == "grid") {
                $out = shell_exec("ls /var/www/cigala/2.0/htdocs/grid/tmp| wc -l");
            }
            if ($display)
                echo $out;
            else
                return $out;
        }
    }

    /**
     * Returns an array with ID and name from stations stored on database.
     * @param null
     * @return Array An associative array with the ID's and column names.
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function getStationsDB($group = FALSE)
    {

        if (is_numeric($group)) {
            $where = " where op_group_label_fk = $group ";
        } else {
            $where = "";
        }

        //$ret = null;
        $sql = "select id, name, status, type, selected from station $where order by name, last_data desc";
        $rs = pg_query($sql);
        if (pg_num_rows($rs)) {
            $ret = pg_fetch_all($rs);
        }
        return $ret;
    }

    /**
     * Returns an array with group labels (for station selectors)
     * @param null
     * @return Array An associative array with the group's data.
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function getStationsGroupLabels()
    {
        $sql = "select group_id, group_name from station_group order by group_order";
        $rs = pg_query($sql);
        if (pg_num_rows($rs)) {
            $ret = pg_fetch_all($rs);
        } else {
            $ret = array();
        }
        return $ret;
    }

    /**
     * Returns an array group details according to the group_fk from the station.
     * @param $group_id Id from station_group table
     * @return Array An associative array with the station_group properties.
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function getStationGroupById($group_id)
    {
        $sql = "select * from station_group where group_id = {$group_id} ";
        $rs = pg_query($sql);
        if (pg_num_rows($rs)) {
            $ret = pg_fetch_all($rs);
            return $ret[0];
        } else {
            return false;
        }
    }

    /**
     * Returns an array with ID and name from stations stored on database with report=true.
     * @param $order field to order the data. Default: lat_
     * @return Array An associative array with the ID's and column names.
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function getStationsToReport($order = "lat_")
    {
        $sql = "select id, name from station where active=true order by $order";
        $rs = pg_query($sql);
        if (pg_num_rows($rs)) {
            $ret = pg_fetch_all($rs);
        }
        return $ret;
    }

    /**
     * Returns an array with ID, name, lat and long from stations stored on database.
     * @param null
     * @return Array An associative array with the ID's and column names.
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function getStationsLatLong()
    {
        $sql = "select id, name, lat_, long_ from station where lat_ is not null and long_ is not null";
        $rs = pg_query($sql);

        $ret = pg_fetch_all($rs);

        return $ret;
    }

    /**
     * Get the distincts ID's from an array with data
     * @param Array $data The input array whith 'id' column
     * @param String $id The name of the column to be distinguished
     * @return Array An  array with the distinct ID's
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function getDistinctFields($data, $field = "id")
    {
        $aux = null;
        // for($i=0; $i<count($data); $i++)
        //         $aux[$data[$i][$field]]='';

        foreach ($data as $row)
            $aux[$row[$field]] = '';


        if ($aux)
            return array_keys($aux);
    }

    function displayApproxXYZ($name)
    {
        $sql = "select x_,y_,z_ from station where name = '$name'";
        $rs = pg_query($sql);
        if ($rs) {
            $st = pg_fetch_all($rs);
            echo implode('; ', $st[0]);
        }
        return;
    }

    /**
     * Get all params from database for a station found by any search field
     * @param String $field the name of the field
     * @param String $search the value of the field
     * @return Array An array with the fields in a associative array
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function getStationByField($field, $search)
    {
        $sql = "select * from station where $field='$search'";
        $rs = pg_query($sql);
        if ($rs) {
            $st = pg_fetch_all($rs);
            return $st[0];
        } else {
            return false;
        }
    }

    /**
     * Set the station id of the object of class Cigala
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function setStationIDByName()
    {
       /*  $sql = "select id from station where name='$this->name'";
        $rs = pg_query($sql);
        if (pg_num_rows($rs)) {
            $ret = pg_fetch_row($rs);
            $this->station_id = $ret[0];
        } //if resultado
        else {
            echo "<br>Warning: station not found: $this->name!!!<br>";
            $this->station_id = -1; //no station found
        } */
    }

    /**
     * Returns the stations associated to queries over the 'stations' table/view
     * @param $str String an string with the comma separeted stations name. Ex: 'pru1, pru2' or SJCI, SJCU, maca
     * @return String string with the properly ids separeted by ',' . Ex: '1,2' or '3,5,6'
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function sqlFormatStations($str)
    {

        if ($str == "all")
            return null;
        else if (is_numeric(trim($str)))
            return $str;

        $stations = split(",", $str); //separete stations in array
        //print_r($stations);

        for ($i = 0; $i < count($stations); $i++) {
            $aux = $this->getStationByField('name', trim(strtoupper($stations[$i])));
            $ids[] = $aux['id']; //get the ids in array
        }
        $ret = implode(',', $ids); //merge comma separated ids - 1,2,3 - for in clause
        //print_r($ids);
        return $ret;
    }

    /**
     * Returns the 'where clause' for queries over data
     * @param $param Associative array with configuration parameters
     * @return String String with the 'where clause' (sql)
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function sqlFormatWhere($param)
    {

        //set the filters - if station_id
        //if($aux=$this->sqlFormatStations($param['station']))
        $where = "time_utc between '{$param['date_begin']}' and '{$param['date_end']}' and ";
        if (isset($param['station'])) { //Ex: 5,6
            $where .= " station_id in ({$param['station']}) and ";
        } else if (isset($param['stationName'])) { // Ex: PRU1,PRU2
            $list = explode(",", $param['stationName']);
            foreach ($list as $st) {
                $station_data = $this->getStationByField("name", $st);
                $st_list[] = $station_data['id'];
            }
            $list_ids = implode(",", $st_list);
            $where .= " station_id in ($list_ids) and ";
        }

        if (isset($param['satellite'])) {
            $sats = explode(',', $param['satellite']); //obtem vetor com as opoes de satelites
            $svids = array();

            foreach ($sats as $sat) {
                $sat = strtoupper($sat); //uppercase
                if ($sat == "GPS") {
                    $aux[] = " (svid < 37) ";
                } else if ($sat == "GLONASS") {
                    $aux[] = " (svid between 38 and 61) ";
                } else if (strtoupper($sat) == "GALILEO") {
                    $aux[] = " (svid between 71 and 102) ";
                } else if ($sat == "SBAS") {
                    $aux[] = " (svid between 120 and 140) ";
                } else if ($sat == "BEIDOU/COMP") {
                    $aux[] = " (svid between 141 and 172) ";
                } else if ($sat == "QZSS") {
                    $aux[] = " (svid between 181 and 187) ";
                } else
                    $svids[] = $sat;
            }

            if (count($svids) > 0) {
                $aux[] = " (svid in (" . implode(",", $svids) . ")) ";
            }

            if (count($aux) > 0) {
                $where .= "(" . implode('or', $aux) . ") and ";
            }
        }

        // filters were included
        if ($param['filters'] != 'null') {
            if (preg_match('/[;]$/', trim($param['filters']))) { //check if user passed extra ";"
                $where .= str_replace(";", " and ", $param['filters']);
            } else { // no ending ";"
                $tmp = explode(";", $param['filters']);
                foreach ($tmp as $f) {
                    $where .= " $f and ";
                }
            }
        }

        //echo $where;

        return $where;
    }


    /**
     * Returns the 'where clause' for queries over data
     * @param $param Associative array with configuration parameters
     * @return String String with the 'where clause' (sql)
     * @author Bruno C. Vani (brunovani22@gmail.com), Rafael S. Santos (rafaelssantos.academico@gmail.com)
     */
    function sqlFormatWhereTime($param)
    {

        //set the filters - if station_id
        //if($aux=$this->sqlFormatStations($param['station']))
        $where = "time_utc between '{$param['date_begin']}' and '{$param['date_end']}' and ";
        $where .= "(time_utc::timestamp::time between '{$param['time']}:00:00'  and '{$param['time']}:59:59') and";
        if (isset($param['station'])) { //Ex: 5,6
            $where .= " station_id in ({$param['station']}) and ";
        } else if (isset($param['stationName'])) { // Ex: PRU1,PRU2
            $list = explode(",", $param['stationName']);
            foreach ($list as $st) {
                $station_data = $this->getStationByField("name", $st);
                $st_list[] = $station_data['id'];
            }
            $list_ids = implode(",", $st_list);
            $where .= " station_id in ($list_ids) and ";
        }

        if (isset($param['satellite'])) {
            $sats = explode(',', $param['satellite']); //obtem vetor com as opoes de satelites
            $svids = array();

            foreach ($sats as $sat) {
                $sat = strtoupper($sat); //uppercase
                if ($sat == "GPS") {
                    $aux[] = " (svid < 37) ";
                } else if ($sat == "GLONASS") {
                    $aux[] = " (svid between 38 and 61) ";
                } else if (strtoupper($sat) == "GALILEO") {
                    $aux[] = " (svid between 71 and 102) ";
                } else if ($sat == "SBAS") {
                    $aux[] = " (svid between 120 and 140) ";
                } else if ($sat == "BEIDOU/COMP") {
                    $aux[] = " (svid between 141 and 172) ";
                } else if ($sat == "QZSS") {
                    $aux[] = " (svid between 181 and 187) ";
                } else
                    $svids[] = $sat;
            }

            if (count($svids) > 0) {
                $aux[] = " (svid in (" . implode(",", $svids) . ")) ";
            }

            if (count($aux) > 0) {
                $where .= "(" . implode('or', $aux) . ") and ";
            }
        }

        // filters were included
        if ($param['filters'] != 'null') {
            if (preg_match('/[;]$/', trim($param['filters']))) { //check if user passed extra ";"
                $where .= str_replace(";", " and ", $param['filters']);
            } else { // no ending ";"
                $tmp = explode(";", $param['filters']);
                foreach ($tmp as $f) {
                    $where .= " $f and ";
                }
            }
        }

        //echo $where;

        return $where;
    }

    /**
     * Creates an gz file handler for reading gz file at ftp. Use default ftp access path and addres
     * @param null
     * @return Array An gz object
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function openGZFile()
    {
        $gz = gzopen("ftp://" . $this->ftp_user . ":" . $this->ftp_password . "@" .
            $this->ftp_host . "/" . $this->name . "/" . $this->year . "/" . $this->day . "/" . $this->file, 'r');

        return $gz;
    }

    /**
     * Closes an gz file handler used for reading gz file at ftp.
     * @param handler $gz the gz handler previously opened
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function closeGZFile($gz)
    {
        gzclose($gz); //close file
    }

    /**
     * Creates the sql statement (with object params station_id, day and file) for
     * insert_log after file was succesfully inserted to database;
     * @param null
     * @return Array An gz object
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function prepareInsertLog()
    {
        $sql = " insert into insert_log(station_id, day_number, filename) values
             ($this->station_id, $this->day, '$this->file'); ";

        return $sql;
    }

    /**
     * Execute insertion query, handling and returning errors. Append the insert_log instruction and make
     * the transaction block for security reasons.
     * @param String $sql the string for insert the lines of the file
     * @return Boolean true on success, false otherwise
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function commitFiletoDB($sql)
    {

        //check if sql was sucessfully created
        if ($sql) {

            //$sql=$sql." ".$this->prepareInsertLog()." ";  //whitouth begin/commit for transaction                   

            $sql = "begin; " . $sql . " " . $this->prepareInsertLog() . " commit; ";  //append begin/commit for transaction


            $rs = pg_query($sql);

            if ($rs) { //ok
                echo " $this->file-$this->day sucessfully inserted and logged.<br>";
                return true;
            } else { //fail on inportation
                echo "Failed to insert $this->file-$this->day - duplicated lines on file or another error.<br>\n";
                echo pg_last_error() . "<br>\n";

                pg_connection_reset($this->db_conn); //reset handler for next file             
                //echo "<br>Bad query:<br> {$sql}<br><hr>";                          

                return false;
            }
        } else { //sql fail - file is corrupted
            echo "Failed to insert $this->file-$this->day  - file is corrupted.<br>";
            return false;
            //echo "Bad query:<br> {$sql}";
        }
    }

    function importLisn($path, $correct_leap = FALSE)
    {
        $sql = '';

        if (!preg_match("/s4.gz/", $this->getFile())) {
            echo "Warning: file name refused: '{$this->getFile()}'. Skipping..\n";
        } else {
            $gz = gzopen($path . "/" . $this->getFile(), "r"); // read file in local folder (path)
            if ($gz) {
                echo "File opened: '{$this->getFile()}'.\n";
                $this->setStationIDByName();
                echo "importing file ({$this->getFile()}-{$this->getName()}, station_id {$this->getStationID()}) ..\n";

                while (!gzeof($gz)) {
                    $linestr = gzgets($gz, 10000); //read a line of file                
                    $col = preg_split("/[\r, \t]+/", $linestr);
                    //echo $linestr . "\n";
                    //print_r($col);

                    if (strlen($col[0]) == 2 && $col[0] >= 10) { //two digit year since 2010
                        $yy = $col[0];
                        $year = 2000 + $col[0];
                        $doy = $col[1]; // from 1 to 366 (lisn format)

                        $doy_php = $doy - 1; // from 0 to 365 (php format)
                        $secs_day = $col[2];
                        $ndata = $col[3];

                        $this->setDay($yy . $doy); // format YYDDD

                        echo "Line: $doy/$year - TOD $secs_day, No. of sat: $ndata ({$this->getFile()}-{$this->getDay()})\n";

                        $date = DateTime::createFromFormat('Y-m-d H:i:s', "$year-1-1 00:00:00");
                        date_add($date, date_interval_create_from_date_string("$doy_php days"));
                        date_add($date, date_interval_create_from_date_string("$secs_day seconds"));
                        $timestamp = $date->format("Y-m-d H:i:s");
                        echo "timestamp file $timestamp \n";

                        if ($correct_leap) {
                            $leap_s = $this->getLeapSeconds($date);
                            echo "Leap seconds found: $leap_s\n";
                            //subtract leap seconds
                            date_sub($date, date_interval_create_from_date_string("$leap_s seconds"));
                            $timestamp = $date->format("Y-m-d H:i:s");
                            echo "timestamp after leap seconds $timestamp \n";
                        }

                        //obtem wn e tow
                        $aux = $this->civil_to_gps($date->format("d"), $date->format("m"), $date->format("Y"), $date->format("H"), $date->format("i"), $date->format("s"));

                        //print_r($aux);
                        $wn = $aux["wn"];
                        $tow = $aux["tow"];

                        for ($i = 0, $j = 0; $i < $ndata; $i++, $j += 3) {
                            $prn = $col[$j + $i + 4];
                            $s4 = $col[$j + $i + 5];
                            $azim = $col[$j + $i + 6];
                            $elev = $col[$j + $i + 7];

                            $sql .= "insert into ismr (filename, station_id, time_utc, wn, tow, svid, s4, azim, elev) values " .
                                "('{$this->getFile()}', {$this->getStationID()}, '$timestamp', $wn, $tow, $prn, $s4, $azim, $elev); \n";
                            //echo $sql;
                            //break;
                        }
                    }
                } //while read lines of file
                //echo $sql;
                $aux = $this->commitFiletoDB($sql);
                $this->closeGZFile($gz);

                return $aux;
            } else {
                echo "Failed to open gz file.";
            }
        }
    }

    /**
     * Prepare insertion query by reading each line of the file 
     * @param Array $lines a line of the file splited into array
     * @return Mixed the sql on success, false in case of error while reading file
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function prepareInsert($lines)
    {
        // The value 62 is used for GLONASS satellites of which the slot number is not known.        
        if ($lines[2] != 62) { //
            //station_id, filename, wn, tow, svid, sbf_block, azim, elev, etc..
            //bug fix - azim and elev might not be = 'nan', set NULL if not available
            if (trim($lines[4]) == "nan" || is_nan($lines[4])) {
                //echo "missing elev..";
                $lines[4] = 'NULL';
            }
            if (trim($lines[5]) == "nan" || is_nan($lines[5])) {
                //echo "missing azim..";
                $lines[5] = 'NULL';
            }

            $sql = " insert into ismr values({$this->station_id},'{$this->file}', 
                 {$lines[0]},{$lines[1]}, 
                 {$lines[2]},{$lines[3]},
                 {$lines[4]},{$lines[5]},
                '$lines[6]','$lines[7]','$lines[8]',
                '$lines[9]','$lines[10]','$lines[11]','$lines[12]','$lines[13]','$lines[14]',
                '$lines[15]','$lines[16]','$lines[17]','$lines[18]','$lines[19]','$lines[20]',
                '$lines[21]','$lines[22]','$lines[23]','$lines[24]','$lines[25]','$lines[26]',
                '$lines[27]','$lines[28]',
                '$lines[29]','$lines[30]','$lines[31]','$lines[32]','$lines[33]','$lines[34]',
                '$lines[35]','$lines[36]','$lines[37]','$lines[38]','$lines[39]','$lines[40]',
                '$lines[41]','$lines[42]','$lines[43]','$lines[44]','$lines[45]','$lines[46]',
                '$lines[47]','$lines[48]',
                '$lines[49]','$lines[50]','$lines[51]','$lines[52]','$lines[53]','$lines[54]',
                '$lines[55]','$lines[56]','$lines[57]','$lines[58]','$lines[59]','$lines[60]',                
		'$lines[61]','{$this->getTimestamp($lines[0],$lines[1])}' ); "; //'$lines[61]' );";


            return $sql;
        }
        return null;
    }

    /**
     * Make the insertion of a file into database. Padding file with 'nan' values if necessary.
     * @param Null uses object parameters for identify the file for insert
     * @return Mixed the log on success, false in case of error while reading file
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function db_insertGZFile()
    {
        $gz = $this->openGZFile();
        $sql = '';

        if ($gz) {
            while (!gzeof($gz)) {
                $lines = gzgets($gz, 10000);
                $lines = explode(",", $lines); //set in array

                if (count($lines) == 45) {   //file with 45 column data - need to padding
                    for ($j = 45; $j < 62; $j++)
                        $lines[$j] = 'nan';
                }
                if (count($lines) == 62) { //filesize ok
                    $sql .= $this->prepareInsert($lines);
                }
            } //while

            $this->closeGZFile($gz);
            $aux = $this->commitFiletoDB($sql);
            return $aux;
        } //if gzopen
        else
            return false;
    }

    /**
     * Search for already inserted files by checking the log on database
     * @param Null uses object parameters station_id and day for identify the day of interest
     * @return Array $ret list with the names of the files already inserted
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function listInsertedFilesByDay()
    {
        echo $sql = "select filename from insert_log where station_id = {$this->station_id}
                and day_number = {$this->day}";
        $rs = pg_query($sql);
        $ret = null;
        $cont = pg_num_rows($rs);
        echo "Files available for {$this->day} / {$this->name} - {$this->station_id}: $cont\n";
        if ($cont>0) {
            for ($i = 0; $i < $cont; $i++) {
                $linha = pg_fetch_array($rs);
                $ret[] = $linha[0];
            }
        }

        return $ret;
    }

    /**
     * Check if a given filename is already inserted on the database for a given station.
     * @return boolean true if the file is already on the database; false otherwise.
     */
    function checkInsertedFileByName()
    {
        $sql = "select * from insert_log where station_id = {$this->station_id}
                and filename = '{$this->getFile()}'";
        $rs = pg_query($sql);

        if (pg_num_rows($rs)) {
            //print_r(pg_fetch_all($rs));
            return true;
        } else {
            return false;
        }
    }

    /**
     * Lists binary files on ftp
     * @param Null uses just parameters of the object(station, year and day) for search in the ftp
     * @return Array $ret list with the names of the binary files
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function listNewBinaryFiles($ftp_root = null)
    {

        if ($ftp_root)
            $this->ftp_changeRootTo($ftp_root);
        //echo ftp_pwd($this->ftp_conn)." \n";


        if (@ftp_chdir($this->ftp_conn, $this->name . "/" . $this->year . "/" . $this->day)) {

            // get contents of the current directory
            $tmp = ftp_nlist($this->ftp_conn, ".");

            //check for files _.gz 
            foreach ($tmp as $t) {
                if (preg_match("/_.gz/", $t)) {
                    $ret[] = $t;
                }
            }

            //came back to ftp root
            ftp_cdup($this->ftp_conn);
            ftp_cdup($this->ftp_conn);
            ftp_cdup($this->ftp_conn);
            return $ret;
        } else
            return false;
    }

    /**
     * Lists the ISMR files on the ftp folder that is not yet inserted on database, i.e. just NEW files
     * @param Null uses just parameters of the object(station, year and day) for search in the ftp
     * @return Array $ret list with the names of the binary files
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function listNewISMRFiles($files_day = 24)
    {
        $ret = array();

        //get already inserted files for check
        if (!($inserted = $this->listInsertedFilesByDay()))
            $inserted = array(); //$inserted[0] = null;

        echo "Files already inserted: " . count($inserted) . "<br>";
        echo "Current dir: " . ftp_pwd($this->ftp_conn) . " <br>";

        //print_r($inserted);
        //maybe new files if not yet 24 files was inserted
        if (count($inserted) < $files_day) {
            $fdr = $this->name . "/" . $this->year . "/" . $this->day;
            if (ftp_chdir($this->ftp_conn, $fdr)) {
                // get contents of the current directory
                $tmp = ftp_nlist($this->ftp_conn, ".");
                //print_r($tmp);
                //check for new files ismr.gz or .ismr which is not yet inserted
                foreach ($tmp as $t) {
                    //add just new files (not in the inserted array)
                    if ((preg_match("/.ismr.gz/", $t) || preg_match("/.ismr/", $t)) && !(in_array($t, $inserted))) {
                        $ret[] = $t;
                    }
                }

                //back to root ftp
                //                ftp_cdup($this->ftp_conn);
                //                ftp_cdup($this->ftp_conn);
                //                ftp_cdup($this->ftp_conn);
                $this->ftp_changeRootTo("/"); // bug fix to support links on ftp - 2019-02-25
            } else {
                echo "Error changing to FTP: $fdr <br>";
            }
        }

        return $ret;
    }

    /**
     * Lists the days folder for a year and station
     * @param Null uses just parameters of the object(station, year) for search in the ftp
     * @return Array $ret list with the days on ftp
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    public function listAllDays()
    {

        $ret = null;
        if (@ftp_chdir($this->ftp_conn, $this->name . "/" . $this->year)) {
            $tmp = ftp_nlist($this->ftp_conn, "."); //list all days
            //days are in folders with numeric names
            foreach ($tmp as $t) {
                if (is_numeric($t)) {
                    $ret[] = $t;
                }
            }

            //back to the source root
            ftp_cdup($this->ftp_conn);
            ftp_cdup($this->ftp_conn);
        }
        return $ret;
    }

    /**
     * Lists the years folders for a station
     * @param Null uses just parameters of the object(station) for search in the ftp
     * @return Array $ret list with the years folters on ftp
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    public function listAllYears()
    {
        if (ftp_chdir($this->ftp_conn, $this->name)) {
            $contents = ftp_nlist($this->ftp_conn, ".");

            for ($i = 0; $i < count($contents); $i++) {
                if (is_numeric($contents[$i])) {
                    $ret[] = $contents[$i];
                }
            } //for
            ftp_cdup($this->ftp_conn);
        }
        return $ret;
    }

    /**
     * Lists the stations folders in the ftp root
     * @param Null uses just parameters of the object(ftp params) for search in the ftp
     * @return Array $ret list with the station folters on ftp
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function listAllStations()
    {
        // get contents of the current directory
        $ret = ftp_nlist($this->ftp_conn, ".");
        return $ret;
    }

    /**
     * Change the ftp root for a specific path.
     * @param String $root the desired rooth path
     * @return Null the path was settes in the ftp_conn parameter
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    public function ftp_changeRootTo($root)
    {
        if (ftp_chdir($this->ftp_conn, $root)) {

            return true;
        } else
            return false;
    }

    /**
     * Connect to database (user mode - user dbcigala - trust local connection) - limited user
     * @param Null uses just parameters of the object (db params)
     * @return Null just set the db_conn parameter (handler) on the object
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function db_user_connect()
    {
        $this->db_setup();
        
        $aux = pg_connect("host=$this->db_host user=$this->db_user2 port=$this->db_port password=$this->db_password2 dbname=$this->db_name");
        
        //echo pg_last_error($aux); 
        //echo pg_result_status($aux);
        if ($aux) {
            // echo "Sucessfully connect to $this->db_name at $this->db_host";
            $this->db_conn = $aux;
            return true;
        }
         else {
            
            $config_file = "/var/www/is/classes/serv_db.json";
            if(file_exists($config_file)){
                    $this->db_setup_file($config_file);
                    $aux = pg_connect("host=$this->db_host user=$this->db_user2 port=$this->db_port password=$this->db_password2 dbname=$this->db_name");
                    
                    if ($aux) {
                        // echo "Sucessfully connect to $this->db_name at $this->db_host";
                        $this->db_conn = $aux;
                        return true;
                    }
            }

        }

        echo "Failed to connect database '$this->db_name' at '$this->db_host' - user connection.<br>";
        return false;
    }

    /**
     * Connect to database (postgres)
     * @param Null uses just parameters of the object (db params)
     * @return Null just set the db_conn parameter (handler) on the object
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function db_connect()
    {
        
        $this->db_setup();

        $aux = @pg_connect("host=$this->db_host user=$this->db_user port=$this->db_port password=$this->db_password dbname=$this->db_name");
        if ($aux) {
            // echo "Sucessfully connect to $this->db_name at $this->db_host";
            $this->db_conn = $aux;
            return true;
        } 
        else { // command line mode - attempt to use local passwd file
            $config_file = "/var/www/is/classes/serv_db.json";
            if(file_exists($config_file)){
                $this->db_setup_file($config_file);
                
                $aux = pg_connect("host=$this->db_host user=$this->db_user port=$this->db_port password=$this->db_password dbname=$this->db_name");
                if ($aux) {
                    // echo "Sucessfully connect to $this->db_name at $this->db_host";
                    $this->db_conn = $aux;
                    return true;
                }
            }
        }

        echo "Failed to connect database '$this->db_name' at '$this->db_host' - default connection.<br>";
        return false;
    }

    /**
     * Disconnect to database (postgres)
     * @param Null uses just parameters of the object (db params)
     * @return Null just close the db_conn parameter on the object
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function db_disconnect()
    {
        @pg_close($this->db_conn);
    }

    /**
     * Connect to ftp, set the passive mode.
     * @param Null uses just parameters of the object (ftp params)
     * @return Null just set the ftp_conn parameter (handler) on the object
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    public function ftp_connect()
    {
        $this->ftp_password = trim(shell_exec("cat serv_ftp.auth"));
        $ftp_conn = ftp_connect($this->ftp_host);

        // login with username and password
        $ls = ftp_login($ftp_conn, $this->ftp_user, $this->ftp_password);

        // check connection
        if ((!$ftp_conn)) {
            echo "FTP connection has failed!<br>";

            //echo "Attempted to connect to $this->ftp_host for user $this->ftp_user.<br>";
            return false;
        } else {
            // echo "Connected to $this->ftp_host, for user $this->ftp_user.<br>";
            // enabling passive mode            
            ftp_pasv($ftp_conn, true);
            $this->ftp_conn = $ftp_conn;
        }
    }

    /**
     * Disconnect to ftp
     * @param Null uses just parameters of the object (ftp params)
     * @return Null just close the ftp_conn parameter on the object
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    public function ftp_disconnect()
    {
        ftp_close($this->ftp_conn);
    }

    /**
     * Autenticate user for access the system
     * @param String $user username
     * @param String $password password for the user
     * @return Mixed $type return the type of user: admin or user, if login is correct. Null otherwise.
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function systemLogin($user, $password)
    {

        $this->db_connect();
        $password = md5($password);

        // Prepare a query for execution
        $rs = pg_prepare($this->db_conn, "my_query", 'select * from users where login=$1 and password=$2');

        // Execute the same prepared query, this time with a different parameter
        $rs = pg_execute($this->db_conn, "my_query", array($user, $password));

        //$sql = "select * from users where login='$user' and password='$password'";
        //$rs= pg_query($sql);    
        if (pg_num_rows($rs)) {
            $ret = pg_fetch_assoc($rs);
            if ($ret['type'])            //get type of user on database
                $type = $ret['type'];
            else                        //users with no type on database have default type="user"
                $type = "user";

            //update user last login
            pg_query_params($this->db_conn, "update users set last_login=LOCALTIMESTAMP where login=$1 and password=$2", array($user, $password));
        } else {
            $type = false;
        }

        $this->db_disconnect();
        return $type;
    }

    /**
     * Create a user for the tool
     * @param String $user new username
     * @param String $password new password for the user
     * @param String $institution Institution of the user     
     * @return Mixed $ret associative array with a message and a flag for error status
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function createUser($user, $password, $institution = "")
    {
        $type = "default"; //crea user with default permission
        $this->db_connect();
        if (strlen($user) >= 4 && strlen($password) >= 4) {
            $password = md5($password);
            $sql = "insert into users (login, email, password, institution, type) values ('$user', '$user', '$password', '$institution', '$type')";
            $rs = @pg_query($sql);
            if ($rs) {
                $ret['message'] = "Succesfully created with default permission.";
            } else {
                $ret['message'] = "Error. Please try another username.";
                $ret['error'] = true;
            }
        } else {
            $ret['message'] = "Error: login and/or password is too short. Try another.";
            $ret['error'] = true;
        }
        $this->db_disconnect();

        return $ret;
    }

    /**
     * List all data from the param user; or a list with all users with no param was passed.
     * @return Mixed $list return a list with data from all users
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function getUsers($login = null)
    {

        $this->db_connect();
        if ($login)
            $sql = "select * from users where login='$login'";
        else
            $sql = "select * from users order by login";

        $rs = pg_query($sql);
        if (pg_num_rows($rs)) {
            $ret = pg_fetch_all($rs);
        }

        $this->db_connect();
        return $ret;
    }

    /**
     * Update users information.
     * @return Mixed $ret an array with error status and a message
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function updateUserBasic($login, $new_login, $email, $inst)
    {
        $this->db_connect();
        if (strlen($login) >= 5) {
            $sql = "update users set login='{$new_login}', email='{$email}', institution='{$inst}'
                  where login='{$login}'";

            $rs = @pg_query($sql); //executa consulta
            if (@pg_affected_rows($rs)) {
                $ret['message'] = "Succesfully updated.<br>";
            } else {
                $ret['message'] = 'Error: try another username.<br>';
                $ret['error'] = true;
            }
        } else {
            $ret['message'] = "Login is too short. Try another.<br>";
            $ret['error'] = true;
        }

        $this->db_disconnect();
        return $ret;
    }

    /**
     * Update users information (call from Adm user)
     * @return Mixed $ret an array with error status and a message
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function updateUserAdm($login, $email, $inst, $permission)
    {
        $this->db_connect();

        $sql = "update users set email='{$email}', institution='{$inst}',type='{$permission}'
             where login='{$login}'";


        $rs = @pg_query($sql); //executa consulta
        if (@pg_affected_rows($rs)) {
            if ($permission != "default") {
                $api_sql = "update users set apikey = MD5(login) where login='" . $login . "'";
                $rs = @pg_query($api_sql); //executa consulta
                if (@pg_affected_rows($rs)) {
                    $ret['message'] = "Succesfully updated.<br>";
                } else {
                    $ret['message'] = 'Error on create API key.<br>';
                }
                $ret['error'] = true;
            } else {
                $ret['message'] = "Succesfully updated.<br>";
            }
        } else {
            $ret['message'] = 'Error on updating.<br>';
            $ret['error'] = true;
        }


        $this->db_disconnect();
        return $ret;
    }

    /**
     * Delete user from database
     * @param type $user
     * @return boolean
     */
    function deleteUser($user)
    {
        $this->db_connect();

        $sql = "delete from users where login='{$user}'";

        $rs = pg_query($sql); #executa consulta
        if (pg_affected_rows($rs)) {
            $ret['message'] = "Succesfully excluded.<br>";
        } else {
            $ret['message'] = 'System error: try again, please.<br>';
            $ret['error'] = true;
        }

        $this->db_disconnect();
        return $ret;
    }

    /**
     * Reset the password of a user of the tool
     * @param String $user new username
     * @param String $temp new temporary password for the user
     * @return Mixed $ret associative array with a message and a flag for error status
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function resetUserPass($user, $temp)
    {
        $this->db_connect();

        if (strlen($temp) >= 5) {
            $new = md5($temp);
            $sql = "update users set password='{$new}' where login='{$user}'";

            $rs = pg_query($sql); #executa consulta
            if (pg_affected_rows($rs)) {
                $ret['message'] = "Succesfully updated.<br>";
            } else {
                $ret['message'] = 'System error: try again, please.<br>';
                $ret['error'] = true;
            }
        } else {
            $ret['message'] = "Password is too short. Try another.<br>";
            $ret['error'] = true;
        }

        $this->db_disconnect();
        return $ret;
    }

    /**
     * Update the password of a user of the tool
     * @param String $login username
     * @param String $old old password
     * @param String $new new password
     * @param String $confirm_new confirm new password
     * @return Mixed $ret associative array with a message and a flag for error status
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function updateUserPass($login, $old, $new, $confirm_new)
    {



        if ($this->systemLogin($login, $old)) {
            $this->db_connect();
            if (strlen($new) >= 5) {
                if ($new == $confirm_new) {
                    $new = md5($new);
                    $sql = "update users set password='{$new}' where login='{$login}'";
                    $rs = pg_query($sql); #executa consulta
                    if (pg_affected_rows($rs)) {
                        $ret['message'] = "Succesfully updated.<br>";
                    } else {
                        $ret['message'] = 'System error: try again, please.<br>';
                        $ret['error'] = true;
                    }
                } else {
                    $ret['message'] = "Please, confirm your password correctly.<br>";
                    $ret['error'] = true;
                }
            } else {
                $ret['message'] = "Password is too short. Try another.<br>";
                $ret['error'] = true;
            }
            $this->db_disconnect();
        } else {
            $ret['message'] = "Current password is incorrect. Try again.<br>";
            $ret['error'] = true;
        }


        return $ret;
    }

    /**
     * Create file log on log path.
     * @param String $filename the name for the file
     * @param String $string the contents for writing the file
     * @return Null just write the file in the log path
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    function createFileLog($filename, $string)
    {
        #$date=date(DATE_RFC822);        
        if ($fp = fopen($this->log_path . $filename . ".htm", "w")) {
            fwrite($fp, $string);
            fclose($fp);
        } else {
            echo "<br>/n Error whle creating file log.";
        }
    }

    /**
     * Create a link list with the last logs on log path.
     */
    function lastLogs()
    {
        $list = shell_exec("ls -t {$this->full_logs_path} | head -10");
        $list = explode("\n", $list);
        unset($list[10]);
        return $list;
    }

    /**
     * Start a time counter at the server that also register ellapsed memory
     * @return time Return the started time at the server
     * @author Bruno C. Vani (brunovani22@gmail.com) 
     */
    function start_counter()
    {
        // Iniciamos o "contador"
        list($usec, $sec) = explode(' ', microtime());
        $time_start = (float) $sec + (float) $usec;
        return $time_start;
    }

    /**
     * Stop a previously started time counter on the server and also register ellapsed memory
     * @param time $time_start The previously started time
     * @param memory $memory_start  Memory ellapsed  (default: 0)
     * @return type
     * @author Bruno C. Vani (brunovani22@gmail.com) (brunovani22@gmail.com)
     */
    function stop_counter($time_start, $memory_start = 0)
    {
        // Terminamos o "contador" e exibimos
        list($usec, $sec) = explode(' ', microtime()); //sec + milisec
        $script_end = (float) $sec + (float) $usec;

        $ret['time'] = round($script_end - $time_start, 2);    //ellapsed time from start    
        $ret['memory'] = round(((memory_get_peak_usage(true) / 1024) / 1024), 2) - $memory_start; //*** total script memory!!! so, diff  ***    
        return $ret;
    }

    /**
     * Display the time ellapsed and memory used by a call
     * @param time_memory $elapsed Elapsed time and memory provided by start_counter and stop_counter calss
     * @param String $str Text to be prepended on default message of ellapsed time and memory
     * @author Bruno C. Vani (brunovani22@gmail.com) (brunovani22@gmail.com)
     */
    function displayEllapsedTimeAndMemory($elapsed, $str)
    {
        echo $str . ' response time: ', $elapsed['time'], ' secs. Memory usage: ',
        $elapsed['memory'], 'Mb <br>';
    }

    /**
     * Utility to provide an associative array of date_time based on a time string to add or subtract time
     * @param date_time $str string expression to be added or subtracted on time
     * @param string $year Year of the time stamp
     * @param string $month Month of the time stamp
     * @param string $day Day of the time stamp
     * @param string $hour Hour of the time stamp
     * @param string $minute Minute of the time stamp
     * @param string $second Second of the time stamp
     * @return type
     * @author Bruno C. Vani (brunovani22@gmail.com) (brunovani22@gmail.com)
     */
    function calcTime($str, $year, $month, $day, $hour, $minute, $second)
    {

        $str = "$year-$month-$day $hour:$minute:$second $str";
        echo "str input time=" . $str . " ";

        $dateTime = date("Y:m:d:H:i:s", strtotime($str));  //create object

        echo "str output time=" . $dateTime . "/n";
        //echo $dateTime;
        $dateTime = split(":", $dateTime);


        $output['year'] = $dateTime[0];
        $output['month'] = $dateTime[1];
        $output['dateTime'] = $dateTime[2];
        $output['hour'] = $dateTime[3];
        $output['minute'] = $dateTime[4];
        $output['second'] = $dateTime[5];

        return $output;
    }

    /**
     * Convert from gps_time (week, time of week in seconds - tow ) to ydhms and Ymd-hms
     * @param integer $gps_week GPS week number
     * @param integer $sec_of_week Time of week (tow) in GPS time
     * @return Array Associative array with time params.
     * @author Source Code from Benjamin W. Remondi (extended and adapted by Bruno C. Vani (brunovani22@gmail.com))
     */
    function gps_to_ydhms($gps_week, $sec_of_week)
    {

        $JAN61980 = 44244;      //define('JAN61980', 44244);
        $JAN11901 = 15385;      //define ('JAN11901' ,15385);
        $SEC_PER_DAY = 86400.0; //define ('SEC_PER_DAY' ,86400.0);

        $mjd = (int) ($gps_week * 7 + ($sec_of_week / $SEC_PER_DAY) + $JAN61980); //11648 + 0.001 + 15385 = 55..
        $fmjd = fmod($sec_of_week, $SEC_PER_DAY) / $SEC_PER_DAY;
        $days_fr_jan1_1901 = (int) ($mjd - $JAN11901);
        $num_four_yrs = (int) ($days_fr_jan1_1901 / 1461);
        $years_so_far = (int) (1901 + 4 * $num_four_yrs);
        $days_left = (int) ($days_fr_jan1_1901 - 1461 * $num_four_yrs);
        $delta_yrs = (int) ((int) ($days_left / 365) - (int) ($days_left / 1460));

        //output
        $output['year'] = (int) ($years_so_far + $delta_yrs);
        $output['yday'] = (int) ($days_left - (365 * $delta_yrs) + 1); //day of year
        $output['hour'] = str_pad((int) ($fmjd * 24.0), 2, 0, STR_PAD_LEFT);
        $output['minute'] = str_pad(round($fmjd * 1440.0 - $output['hour'] * 60.0), 2, 0, STR_PAD_LEFT); //$output['minute']= str_pad((int)($fmjd*1440.0 - $output['hour']*60.0), 2, 0, STR_PAD_LEFT );
        $output['second'] = str_pad((round($fmjd * 86400.0 - $output['hour'] * 3600.0 - $output['minute'] * 60.0) + 0), 2, 0, STR_PAD_LEFT);

        $date = new DateTime("{$output['year']}-1-1"); //get the first day of the out year
        $el = $output['yday'] - 1; //subtract 1 day number 
        $date->modify("+{$el} days");
        $tmp = $date->format('Y-n-j');
        $tmp = split("-", $tmp);

        $output['month'] = str_pad($tmp[1], 2, 0, STR_PAD_LEFT);
        $output['day'] = str_pad($tmp[2], 2, 0, STR_PAD_LEFT);

        return $output;
    }

    /**
     * Convert from ydhms to gps_time (week, time of week in seconds - tow ) 
     * @param integer $year Year
     * @param integer $yday Day of year
     * @param integer $hour Hour
     * @param integer $minute Minute
     * @param integer $second Second
     * @return Array Associative array with time params.
     * @author Source Code from Benjamin W. Remondi (extended and adapted by Bruno C. Vani (brunovani22@gmail.com))
     */
    function ydhms_to_gps($year, $yday, $hour, $minute, $second)
    {
        //,int *gps_week, double *sec_of_week)
        $JAN61980 = 44244;      //define('JAN61980', 44244);
        $JAN11901 = 15385;      //define ('JAN11901' ,15385);
        $SEC_PER_DAY = 86400.0; //define ('SEC_PER_DAY' ,86400.0);

        $mjd = (int) (($year - 1901) / 4) * 1461 + (($year - 1901) % 4) * 365 + $yday - 1 + $JAN11901;
        $fmjd = (($second / 60.0 + $minute) / 60.0 + $hour) / 24.0;

        //output
        $output['gps_week'] = (int) (($mjd - $JAN61980) / 7);
        $output['sec_of_week'] = (int) (($mjd - $JAN61980) - $output['gps_week'] * 7 + $fmjd) * $SEC_PER_DAY;

        return $output;
    }

    /**
     * Return GPS time from timestamp (sql) ("Y:m:d:H:i:s")
     * @param type $timestamp
     * @return type
     */
    function timestamp_to_gps($timestamp)
    {
        $e = new DateTime($timestamp);
        $out = $this->civil_to_gps($e->format("j"), $e->format("n"), $e->format("Y"), $e->format("G"), (int) $e->format("i"), (int) $e->format("s"));

        return ($out);
    }

    /**
     * Convert from gregorian date to gps time
     * @return Array Associative array with time params.
     * @author Source Code from Paulo Sergio de Oliveira Jr. and Daniele B. M. Alves (translated to PHP by Bruno C. Vani (brunovani22@gmail.com))
     */
    function civil_to_gps($dia_civil, $mes_civil, $ano_civil, $hora, $minuto, $segundo)
    {

        $hora_dec = $hora + ($minuto / 60) + ($segundo / 3600);
        if ($mes_civil <= 2) {
            $ano = $ano_civil - 1;
            $mes = $mes_civil + 12;
        } //if
        else {
            $ano = $ano_civil;
            $mes = $mes_civil;
        } //else

        $JD = (int) (365.25 * $ano) + (int) (30.6001 * ($mes + 1)) + $dia_civil + ($hora_dec / 24) + 1720981.5;
        $Semana_GPS = (int) (($JD - 2444244.5) / 7);
        $Dia_GPS = abs((int) ($JD + 1.5) % 7); //dia da semana
        $Seg_GPS = ($Dia_GPS * 24 * 3600) + ($hora_dec * 3600);


        $output['wn'] = $Semana_GPS;
        $output['wday'] = $Dia_GPS;
        $output['tow'] = $Seg_GPS;

        return $output;
    }

    /**
     * Returns the number of leap seconds based on timestamp date.
     * Warning: Valid from 2009 to 2016!
     * Ref: IERSS Bulletin C (https://www.iers.org/IERS/EN/Publications/Bulletins/bulletins.html)
     * @param type $timestamp
     */
    function getLeapSeconds(DateTime $epoch)
    {

        $leap = 33; //before 2008-12-31 23:59:59
        $p = 0;   //posic of vector index

        $ref[$p]['epoch'] = new DateTime("2008-12-31 23:59:59"); // 33s p/ 34s
        $ref[$p]['value'] = 34;

        $p++;
        $ref[$p]['epoch'] = new DateTime("2012-06-30 23:59:59"); // 34s p/ 35s
        $ref[$p]['value'] = 35;

        $p++;
        $ref[$p]['epoch'] = new DateTime("2015-06-30 23:59:59"); // 35s p/ 36s
        $ref[$p]['value'] = 36;

        $p++;
        $ref[$p]['epoch'] = new DateTime("2016-12-31 23:59:59"); // 36s p/ 37s
        $ref[$p]['value'] = 37;

        for ($i = 0; $i < count($ref); $i++) {
            if ($epoch >= $ref[$i]['epoch']) {
                $leap = $ref[$i]['value'];
            }
        }
        $leap_gps = $leap - 19;
        echo "UTC leap: $leap; GPS leap: $leap_gps\n";
        return $leap_gps;
    }

    /**
     * Prepare a timestamp string (y-m-d h:i:s) from GPS time (wn and tow )
     * @return String the timestamp string
     * @author Bruno C. Vani (brunovani22@gmail.com) (brunovani22@gmail.com)
     */
    function getTimestamp($wn, $tow)
    {
        $tmp = $this->gps_to_ydhms($wn, $tow);
        $timestamp = "{$tmp['year']}-{$tmp['month']}-{$tmp['day']} {$tmp['hour']}:{$tmp['minute']}:{$tmp['second']}";
        return $timestamp;
    }

    /**
     * Provides a classification task over the S4 index and persist to DBMS
     * @param integer $wn The GPS week number to be classified
     * @author Bruno C. Vani (brunovani22@gmail.com) (brunovani22@gmail.com)
     */
    function makeClassificationS4($wn)
    {

        //$query="BEGIN;" ;                 
        for ($tow = 0; $tow < 604800; $tow += 60) {
            echo "\n->$wn/$tow..\n";

            //get the time_ut
            $timestamp = $this->getTimestamp($wn, $tow);

            //computing the high values
            $sql = "select t1.low, t2.moderate, t3.high, 
                    (t1.low+t2.moderate+t3.high) as total,        
                    (t1.low/(t1.low+t2.moderate+t3.high)::real) as avg_low, 
                    (t2.moderate/(t1.low+t2.moderate+t3.high)::real) as avg_moderate,
                    (t3.high/(t1.low+t2.moderate+t3.high)::real) as avg_high
                     from 
                    (select count(s4) as low from ismr where station_id={$this->station_id} and wn={$wn} and tow={$tow} and svid < 37 and s4 < 0.5) as t1, 
                    (select count(s4) as moderate from ismr where station_id={$this->station_id} and wn={$wn} and tow={$tow} and svid < 37 and s4 between 0.5 and 1) as t2,
                    (select count(s4) as high from ismr where station_id={$this->station_id} and wn={$wn} and tow={$tow} and svid < 37 and s4 > 1 ) as t3 ";
            //echo $sql;         

            $rs = pg_query($sql);
            if (pg_num_rows($rs)) {
                $aux = pg_fetch_assoc($rs);
                //print_r($aux);

                $query = " insert into s4gpstiwari " .
                    "(time_utc, wn, tow, low, moderate, high, avg_low, avg_moderate, avg_high, total ) " .
                    "values ( '{$timestamp}', {$wn}, {$tow}, " .
                    "{$aux['low']}, {$aux['moderate']},{$aux['high']}, " .
                    "{$aux['avg_low']},{$aux['avg_moderate']},{$aux['avg_high']},{$aux['total']} ); ";

                $rs = pg_query($query); //insere novos dados

                if (!$rs) {
                    echo "An error occurred on $wn and $tow. Bad query:\n $query \n\n";
                }
            } //if resultado                                              
        } //for                    
        //$query.="END;";               
        //echo $query;        
    }

    /**
     * This method provide timestamp values (format: y-m-d h:i:s) for unlabeled data.
     * @param integer $wn The GPS week number to be time stamped
     * @author Bruno C. Vani (brunovani22@gmail.com) (brunovani22@gmail.com)
     */
    function makeTimestamp($wn)
    {
        echo "\nPreparing query..\n\n";
        $query = "begin; ";

        for ($tow = 0; $tow < 604800; $tow += 60) {

            echo "\n->$wn/$tow..\n";

            $timestamp = $this->getTimestamp($wn, $tow);
            $query = " update ismr set time_utc='{$timestamp}' where wn=$wn and tow=$tow; ";

            $rs = pg_query($query); #executa consulta
            if (pg_affected_rows($rs)) {
                // echo $ret['message']="Succesfully updated - wn={$wn}/tow={$tow}.\n";                   
            } else {
                echo $ret['message'] = "Error on updating wn={$wn}/{$tow}.\n";
                $ret['error'] = true;
            }
        }
        $query .= " end;";
        return $ret;
    }

    /**
     * This method generates a table of data availability based on historical ISMR data.
     * Monthly availability is considered. 
     * @param int $year The year that will be checked (used only for label purposes).
     * @param timestamp $t0 Start epoch
     * @param timestamp $t1 End epoch
     * @param boolean $show_y Display the year line?
     * @param boolean $show_m Display the month line?
     * @return boolean|string A html table with monthly availability or false.
     */
    function getYearlyAvailability($year, $t0, $t1, $show_y = false, $show_m = false)
    {
        $sql = "select distinct month,year from ismr_views where station_id='{$this->getStationID()}' "
            . "and time_utc between '$t0' and '$t1' "
            . "order by month,year";
        $rs = pg_query($this->db_conn, $sql);
        if (pg_num_rows($rs)) {
            $str = "";


            //line - year
            if ($show_y) {
                $str .= "<tr class='info'><th>Year</th><th colspan='12'> $year <th></tr>\n";
            }

            //line - month
            if ($show_m) {
                $str .= "<tr class='info'><th>Month</th>\n";

                for ($i = 1; $i <= 12; $i++) {
                    $str .= "<th>" . date('M', mktime(0, 0, 0, $i, 10)) . " </th>\n";
                }

                $str .= "</tr>\n";
            }

            //fetch all month for this year
            while ($item = pg_fetch_assoc($rs)) {
                $month[] = $item['month'];
            }

            //line - availab.
            $str .= "<tr> <th> {$this->getName()} </th>\n";
            for ($i = 1; $i <= 12; $i++) {
                if (in_array($i, $month)) {
                    $str .= "<td> x </td>\n";
                } else {
                    $str .= "<td> &nbsp; </td>\n";
                }
            }
            $str .= "</tr>\n";

            file_put_contents("avail/{$this->getName()}_$year.txt", $str);

            return $str;
        } else {
            return false;
        }
    }

    /**
     * Return a list of the Pakistan fixed stations available each year (starting from 2015).
     * The list is defined manually for preventing time consuming queries.
     * @param int $year The year to be retrieved.
     * @return array An associative array with the stations.
     */
    function getFixedListNamesPak($year)
    {
        $stations = array(
            //"ALL" => "ALL", //not available yet
            "QUET" => "QUET",
            "MULT" => "MULT",
            "ISLD" => "ISLD"
        );

        return $stations;
    }

    /**
     * Return a list of the fixed stations available each year (starting from 2011).
     * The list is defined manually for preventing time consuming queries.
     * @param int $year The year to be retrieved.
     * @return array An associative array with the stations.
     */
    function getFixedListNames($year)
    {
        $stations = array(
            "ALL" => "ALL",
            "MANA" => "MANA",
            "MAN2" => "MAN2",
            "MAN3" => "MAN3",
            "SLMA" => "SLMA",
            "FORT" => "FORT",
            "PALM" => "PALM",
            "UFBA" => "UFBA",
            "PRU1" => "PRU1",
            "PRU2" => "PRU2",
            "INCO" => "INCO",
            "SJCI" => "SJCI",
            "SJCE" => "SJCE",
            "SJCU" => "SJCU",
            "MACA" => "MACA",
            "MAC2" => "MAC2",
            "MAC3" => "MAC3",
            "POAL" => "POAL"
        );
        if ($year == 2011) {
            unset($stations["SLMA"]);
            unset($stations["FORT"]);
            unset($stations["INCO"]);
            unset($stations["UFBA"]);
            unset($stations["MAN2"]);
            unset($stations["MAN3"]);
            unset($stations["SJCE"]);
            unset($stations["MAC2"]);
            unset($stations["MAC3"]);
        } else if ($year == 2012) {
            unset($stations["SLMA"]);
            unset($stations["FORT"]);
            unset($stations["INCO"]);
            unset($stations["UFBA"]);
            unset($stations["MAN2"]);
            unset($stations["MAN3"]);
            unset($stations["MACA"]);
            unset($stations["MAC3"]);
        } else if ($year == 2013) {
            unset($stations["SLMA"]);
            unset($stations["MANA"]);
            unset($stations["MAN3"]);
            unset($stations["MACA"]);
            unset($stations["MAC3"]);
            unset($stations["SJCI"]);
            $stations["PRU3"] = "PRU3";
            $stations["GALH"] = "GALH";
            $stations["MORU"] = "MORU";
        } else if ($year == 2014) {
            unset($stations["MANA"]);
            unset($stations["MAN2"]);
            unset($stations["MACA"]);
            unset($stations["MAC3"]);
            unset($stations["SJCI"]);
            //MTA stations
            $stations["PRU3"] = "PRU3";
            $stations["GALH"] = "GALH";
            //ICEA stations
            $stations["AFAE"] = "AFAE";
            $stations["AFAW"] = "AFAW";
            $stations["BACG"] = "BACG";
            $stations["EEAR"] = "EEAR";
            $stations["GLTW"] = "GLTW";
        } else if ($year == 2015) {
            unset($stations["MANA"]);
            unset($stations["MAN2"]);
            unset($stations["MACA"]);
            unset($stations["SJCI"]);
        }

        if ($year == 2016) {
            $stations = array(
                "ALL" => "ALL",
                "MAN3" => "MAN3",
                "SLMA" => "SLMA",
                "FRTZ" => "FRTZ",
                "PALM" => "PALM",
                "UFBA" => "UFBA",
                "PRU1" => "PRU1",
                "PRU2" => "PRU2",
                "INCO" => "INCO",
                "SJCE" => "SJCE",
                "SJCU" => "SJCU",
                "MAC3" => "MAC3",
                "POAL" => "POAL"
            );
        } else if ($year == 2017) {
            $stations = array(
                "ALL" => "ALL",
                "SLMA" => "SLMA",
                "FRTZ" => "FRTZ",
                "PALM" => "PALM",
                "UFBA" => "UFBA",
                "PRU1" => "PRU1",
                "PRU2" => "PRU2",
                "INCO" => "INCO",
                "SJCE" => "SJCE",
                "SJCU" => "SJCU",
                "MAC3" => "MAC3",
                "POAL" => "POAL"
            );
        }

        return $stations;
    }

    /**
     * This method update the first_date and last_date information about the stations on DB.
     * The update is derived from the first and last day_number for each station in the insert_log table.
     */
    function setFirstLastDataDates()
    {
        $sql_u = "begin;"; //sql to update dates

        $sql = "select station_id, min(day_number), max(day_number) "
            . "from insert_log group by station_id order by station_id";

        $rs = pg_query($sql);

        if (pg_num_rows($rs)) {
            $aux = pg_fetch_all($rs);
            foreach ($aux as $row) {

                $yy_ini = substr($row['min'], 0, 2);
                $doy_ini = substr($row['min'], 2, 5);
                $d1 = DateTime::createFromFormat('y-z', "$yy_ini-$doy_ini");
                date_sub($d1, date_interval_create_from_date_string("1 day"));
                $d1_st = $d1->format("Y-m-d");
                //echo "{$row['min']} $yy_ini $doy_ini $d1_st \n";

                $yy_end = substr($row['max'], 0, 2);
                $doy_end = substr($row['max'], 2, 5);
                $d2 = DateTime::createFromFormat('y-z', "$yy_end-$doy_end");
                date_sub($d2, date_interval_create_from_date_string("1 day"));
                $d2_st = $d2->format("Y-m-d");
                //echo "{$row['max']} $yy_end $doy_end $d2_st \n";

                $sql_u .= "update station set first_data = '$d1_st', last_data = '$d2_st' "
                    . "where id = {$row['station_id']}; \n";
            }
            $sql_u .= "commit;";

            //echo $sql_u;

            $rs2 = pg_query($sql_u);
            if ($rs2) {
                echo "\n<br> First / Last dates updated successfully.<br>\n";
            } else {
                echo "\n<br> Error while updating first / last dates.<br>\n";
            }
        }
    }

    /**
     * This method checks the FTP repository and look for new data to be imported on DBMS. The
     * method can be invoked under a cron task and has the ability to import just new data.
     * @return String the log of the Import process.
     */
    function importation($stations_arg = array(), $years_arg = array(), $mode_cli = false)
    {
        if (!$mode_cli) {
            ob_start(); //stop stdout for writing file
        }

        //BEGIN IMPORT Process /////////////////////////////////////////////
        echo "------ ISMR import log --------<br>";
        echo "initial time: " . date("Y.m.d-H.i") . "<br>";
        echo "please wait...don't refresh the page.<br>";

        if (count($stations_arg) == 0) {
            $all_stations = $this->listAllStations();
        } else {
            $all_stations = $stations_arg;
        }

        //$all_stations = array("BRAS"); //adjust for manual use 
        print_r($all_stations);

        foreach ($all_stations as $station_name) {
            //$this->setName($station_name);
            $info_st = $this->getStationByField('name', $station_name);

            if (!$info_st) {
                echo "Station $station_name not found on DB. <br>\n";
            } else {
                $files_day = $info_st['files_day'];
                if ((int) $files_day == 0) {
                    $files_day = 24;
                }
                $this->setName($info_st['name']);

                if (count($years_arg) == 0) {
                    //$all_years = $this->listAllYears(); //all years of station 
                    //sort($all_years);
                    $all_years[0] = date('Y'); //forces just current year                         
                } else {
                    $all_years = $years_arg;
                }

                print_r($all_years);

                if (count($all_years > 0)) {
                    foreach ($all_years as $year) {
                        $this->setYear($year); //set year param                    
                        echo "<hr>Station: {$this->getName()} ({$this->getStationID()}) --Year: {$this->getYear()}<br>\n";
                        echo "Current dir: " . ftp_pwd($this->ftp_conn) . " <br>\n";
                        echo "Expected files by day: $files_day <br>\n";

                        $inserted[$station_name] = 0;
                        $failed[$station_name] = 0;

                        //gets not imported succesfully days 
                        if ($all_days = $this->listAllDays()) {
                            //print_r($all_days);
                            //$all_days = array(17250); //adjust for manual use
                            foreach ($all_days as $day) {
                                echo "<br>Station: {$this->getName()} ({$this->getStationID()}) --Year: {$this->getYear()} ----Folder: $day <br>\n";
                                $this->setDay($day); //set day param                            
                                //new files inside a day
                                $new_files = $this->listNewISMRFiles($files_day);
                                //print_r($new_files);

                                if (count($new_files) > 0) {
                                    //print_r($new_files);
                                    echo "Exibindo arquivos a inserir:<br>\n";
                                    foreach ($new_files as $file) {
                                        echo "<br>Station: {$this->getName()} ({$this->getStationID()}) --Year: {$this->getYear()} ";
                                        echo "------File: $file <br>\n";
                                        if (substr($file, 0, 4) == $this->getName()) {
                                            $this->setFile($file); //set file param                                            
                                            if ($this->db_insertGZFile()) //call insertion function
                                                $inserted[$station_name]++;
                                            else
                                                $failed[$station_name]++;
                                        } else {
                                            echo "Warning!! Name not matched!!! $file<br>\n";
                                            $failed[$station_name]++;
                                        }
                                    }   //foreach file 
                                } //new files                                
                            } //foreach day                         
                        }
                        echo "<br>";
                    } //foreach year                                                        
                } //if station has year            
            } //if station exists on db 
        } //foreach station
        //fotter for log
        echo "<br><HR><BR>\n";
        echo "Total inserted files: <br>\n";
        foreach ($inserted as $key => $value) {
            echo $key . " => $value <br>";
        }
        echo "<br><HR><BR>";
        echo "Total failed files: <br>\n";
        foreach ($failed as $key => $value) {
            echo $key . " => $value <br>";
        }

        $this->setFirstLastDataDates();

        echo "<br>final time: " . date("Y.m.d-H.i");

        //for create log file     
        if (!$mode_cli) {
            $contents = ob_get_contents();
            return $contents;
        }
    }

    /**
     * Create a requisition for a new user 
     * @param String $login new name
     * @param String $email new email for the user
     * @param String $institution Institution of the user     
     * @return Mixed $ret associative array with a message and a flag for error status
     * @author Renan P. Biazini (renan.biazini@gmail.com)
     */
    function createRequisition($login, $email, $institution = "", $description = "")
    {
        $this->db_connect();
        $sql = "insert into requisitions (login, email, institution, description) values ('$login', '$email', '$institution', '$description')";
        $rs = @pg_query($sql);
        if ($rs) {
            $ret['message'] = "Succesfully created.";
            $ret['error'] = false;
        } else {
            $ret['message'] = "Error. It was not possible to create the requisition.";
            $ret['error'] = true;
        }

        $this->db_disconnect();

        return $ret;
    }

    /**
     * List all data from requisitions that have not been processed.
     * @return Mixed $list return a list with data from all requisitions
     * @author Renan P. Biazini (renan.biazini@gmail.com)
     */
    function getRequisitionsNotProcessed()
    {

        $this->db_connect();

        $sql = "select * from requisitions where date_registration is null order by date_requisition";

        $rs = pg_query($sql);
        if (pg_num_rows($rs)) {
            $ret = pg_fetch_all($rs);
        }

        $this->db_connect();
        return $ret;
    }

    /**
     * Update a requisition in registration 
     * @param int $idRequisition id of requisition        
     * @return Mixed $ret associative array with a message and a flag for error status
     * @author Renan P. Biazini (renan.biazini@gmail.com)
     */
    function inactivateRequisition($idRequisition)
    {
        $this->db_connect();
        $sql = "update requisitions set date_registration = now() where id ='$idRequisition'";
        $rs = @pg_query($sql);
        if ($rs) {
            $ret['message'] = "Succesfully updated.";
            $ret['error'] = false;
        } else {
            $ret['message'] = "Error. It was not possible to update the requisition.";
            $ret['error'] = true;
        }

        $this->db_disconnect();

        return $ret;
    }

    /**
     * Checks whether an api key is valid.
     * @param String $apiKey is user's api key 
     * @return boolean Returns true if the key is found and otherwise false
     * @author Renan P. Biazini (renan.biazini@gmail.com)
     */
    function verifyApiKey($apiKey)
    {

        $this->db_connect();

        $sql = "select * from users where apikey ='$apiKey'";

        $rs = pg_query($sql);
        if (pg_num_rows($rs)) {
            return true;
        }

        $this->db_connect();
        return false;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name, $set_id = true)
    {
        $this->name = $name;
        /* if ($set_id) {
            $this->setStationIDByName();
        } */
    }

    public function getYear()
    {
        return $this->year;
    }

    public function setYear($year)
    {
        $this->year = $year;
    }

    public function getDay()
    {
        return $this->day;
    }

    public function setDay($day)
    {
        $this->day = $day;
    }

    public function getFile()
    {
        return $this->file;
    }

    public function setFile($file)
    {
        $this->file = $file;
    }

    public function getStationID()
    {
        return $this->station_id;
    }

    public function setStationID($id)
    {
        $this->station_id = $id;
    }



    //unnused/
    // /**
    // * Returns the age of the 
    // * @param type $creation
    // * @return type
    // */
    //function ageOfStation($creation){
    //    //select CURRENT_DATE -  DATE '2011-12-31' //postgres    
    //    $sql="SELECT date(now()) - date('$creation') AS total";
    //    $rs= pg_query($sql);
    //    if( pg_num_rows($rs) ){
    //        $ret = pg_fetch_assoc($rs);
    //    }
    //    else{
    //        $ret = NULL;
    //    }
    // 
    //    return $ret;
    //}
}

//end of class
