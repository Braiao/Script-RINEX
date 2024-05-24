<?php

/**
 * Description of Rinex
 *
 * @author bruno
 */
require_once 'Cigala.php';



class CigalaRinex extends Cigala {

    private $base_dir = __DIR__;
    private $tmp = $base_dir . '/tmp/';
    public $rinex_path = $tmp;
    public $sbf2rin_exe = "/opt/Septentrio/RxTools/bin/sbf2rin"; //"/opt/Septentrio/RxTools/bin/sbf2rin";
    public $sbf2ismr_exe = "/opt/Septentrio/RxTools/bin/sbf2ismr"; //"/opt/Septentrio/RxTools/bin/sbf2ismr";
    public $x;
    public $y;
    public $z;
    public $antenna_number;
    public $antenna;
    public $tmp_folder = null;
    public $letter_hour = null;

    public function setLetter_hour($letter)
    {
        $this->letter_hour = $letter;
    }

    public function getLetter_hour()
    {
        return $this->letter_hour;
    }

    function setTmp_folder() {
        if (is_null($this->tmp_folder)) {
            $this->tmp_folder = "tmp/" . $_SESSION['login'] . "_" . md5(uniqid(time()));
        }

        if (!file_exists($this->tmp_folder)) {
            echo "Preparing link to download files...<br>";
            mkdir($this->tmp_folder);
        }
    }

    function downloadFromFTP($date_begin, $date_end, $version = "2.11") {
        $t0 = DateTime::createFromFormat('Y-m-d', $date_begin);
        $tf = DateTime::createFromFormat('Y-m-d', $date_end);

        $year0 = ((int) $t0->format("Y"));
        $yearf = ((int) $tf->format("Y"));
        $doy0 = ((int) $t0->format("z")) + 1;
        $doyf = ((int) $tf->format("z")) + 1;

        if ($doy0 > $doyf) {
            $flag_change_year = true;
            echo "Change year active..<br>";
        } else {
            $flag_change_year = false;
        }


        $amount_days = ($yearf + ($doyf / 365)) - ($year0 + ($doy0 / 365));

        //echo "estimated days: $amount_days <br> ";
        if ($amount_days > 0.09) {
            echo "Sorry! This interval is too large. Please, select at most ~31 days at time. <br>";
            die();
        }




        $downloaded = array();
        $this->ftp_connect();

        for ($y = $year0; $y <= $yearf; $y++) {
            
            if ($version == "2.11") {
                //echo "Nav. to rinex2 <br>";
                $this->ftp_changeRootTo("/RINEX2");
            } else {
                //echo "nav to rinex 3 <br>";
                $this->ftp_changeRootTo("/RINEX3");
            }

            //echo "year: $y<br>";

            if ($version == "2.11") { // RINEX2/STAT/YYYY
                $this->ftp_changeRootTo("{$this->getName()}/$y");
            } else { // RINEX3/YYYY
                $this->ftp_changeRootTo("$y");
            }

            //debug only
            //$tmp1 = ftp_nlist($this->ftp_conn, ".");
            //print_r($tmp1);

            $basket_year = array();

            if ($flag_change_year) {
                $doyf_backup = $doyf;
                $doyf = 366;
            }

            // for each day inside the year
            for ($doy = $doy0; $doy <= $doyf; $doy++) {
                $ddd = sprintf("%03d", $doy);
                $yy = substr($y, 2, 4);

                if ($version == "2.11") { // rinex 2 inside year folder
                    $rinex = "{$this->getName()}{$ddd}0.{$yy}o.gz";
                    $tmp2 = ftp_nlist($this->ftp_conn, ".");
                } else { // rinex 3 inside doy folder
                    $rinex = "{$this->getName()}{$ddd}0.{$yy}d.zip";
                    if ($this->ftp_changeRootTo("$ddd")) {
                        $tmp2 = ftp_nlist($this->ftp_conn, ".");
                        $this->ftp_changeRootTo("../");
                    } else {
                        $tmp2 = array();
                    }
                   // print_r($tmp2);
                }

                //echo "Looking for file $rinex <br>";
                if (in_array($rinex, $tmp2)) {
                    echo "File $rinex available. <br>";
                    if ($version == "2.11") {
                        $basket_year[] = array("local" => $rinex, "remote" => $rinex);
                    } else {
                        $basket_year[] = array("local" => $rinex, "remote" => "$ddd/$rinex");
                    }
                } else {
                    echo "File $rinex not found. <br>";
                }
            }

            if ($flag_change_year) {
                $flag_change_year = false;
                $doy0 = 1;
                $doyf = $doyf_backup;
            }

            //print_r($basket_year);
            
            //if there are files in the list
            if (count($basket_year) > 0) {
                foreach ($basket_year as $item) {
                    $remote_file = $item["remote"];
                    $local_file = $item["local"];
                    //echo "obtaining $remote_file to $local_file <br>";
                    $this->setTmp_folder();
                    //echo "current folder: ".ftp_pwd($this->ftp_conn)."<br>";

                    if (ftp_get($this->ftp_conn, "{$this->tmp_folder}/{$local_file}", $remote_file, FTP_BINARY)) {
                        $downloaded[] = $rinex;
                    } else {
                        echo "Failed to download $rinex <br>";
                    }
                }
            }

            
        }

        //print_r($downloaded);

        if (count($downloaded) > 0) {
            $tar_file = $this->tmp_folder . ".tar";
            $phar = new PharData($tar_file); // create empty tar file
            // add all downloaded files in the tar file
            $phar->buildFromDirectory($this->tmp_folder);

            if (file_exists($tar_file)) {
                echo "<a href='$tar_file'> Download your files </a>";
            } else {
                echo "Error preparing link to download.";
            }
        } else {
            echo "Error preparing files to download link.";
        }
    }

    function formatPRN($svid) {
        if ($svid <= 37) {
            return "G" . str_pad($svid, 2, "0", STR_PAD_LEFT);
        }
    }

    function excludeSatRinexTeqc($param, $nl = null) {

        if ($nl) {
            $nl = "<br>\n";
        } else {
            $nl = "\n";
        }

        $cmd[] = "#!/bin/bash";
        $cmd[] = "# About and usage: rules_teqc.sh RINEXFILE";
        $cmd[] = "# This script overwrite the RINEXFILE with the rules presseted, but creates a backup named RINEXFILE.bak";
        $cmd[] = "if [ $# -ne 1 ]; then echo \"$0 usage: $0 [rinex_file]\"; exit 1; fi";
        $cmd[] = "rinex_in=$1";
        $cmd[] = "cat \$rinex_in > \"\$rinex_in.bak\"";
        foreach ($_REQUEST['rinex_editor'] as $rule) {
            $expr = explode(";", $rule);

            $time_begin = new DateTime($expr[0]); //tempo recorte

            $b_time_begin = new DateTime($expr[0]); //instante antes do recorte
            date_sub($b_time_begin, date_interval_create_from_date_string('+1 second'));

            $time0 = new DateTime($expr[0]); //0h do dia
            $time0->setTime(0, 0, 0);


            $time_end = new DateTime($expr[1]); //tempo final recorte
            $a_time_end = new DateTime($expr[1]); //instante apos recorte
            date_sub($a_time_end, date_interval_create_from_date_string('-1 second'));
            $time99 = new DateTime($expr[1]); //ultima epoca do dia
            $time99->setTime(23, 59, 59);


            $PRN = $this->formatPRN($expr[2]);

            $cmd[] = "# processing PRN=$PRN";
            $rinex_list = "";
            if ($time_begin > $time0) {
                $cmd[] = "teqc -phc -st " . date_format($time0, 'YmdHis') .
                        " -e " . date_format($b_time_begin, 'YmdHis') . " \$rinex_in > \"\$rinex_in.pre\"";
                $rinex_list .= "\"\$rinex_in.pre\" ";
            }

            $cmd[] = "teqc -phc -st " . date_format($time_begin, 'YmdHis') .
                    " -e " . date_format($time_end, 'YmdHis') . " -{$PRN} \$rinex_in > \"\$rinex_in.cut\"";
            $rinex_list .= "\"\$rinex_in.cut\" ";

            if ($time_end < $time99) {
                $cmd[] = "teqc -phc -st " . date_format($a_time_end, 'YmdHis') .
                        " -e " . date_format($time99, 'YmdHis') . " \$rinex_in > \"\$rinex_in.pos\"";
                $rinex_list .= "\"\$rinex_in.pos\" ";
            }

            $cmd[] = "teqc $rinex_list > \$rinex_in";
        }

        //file splicing
        if (isset($_REQUEST['window_chk'])) {
            /* if(isset($_REQUEST['window0'])) {
              $start=new DateTime($_REQUEST['window0']);
              $cmd[]="teqc -phc -st ".date_format($start, 'YmdHis')." \$rinex_in > \"\$rinex_in.pre\"";
              $cmd[]="teqc -phc -st ".date_format($start, 'YmdHis')." \$rinex_in.pre > \"\$rinex_in\"";
              }
              if(isset($_REQUEST['windowf'])) {
              $end=new DateTime($_REQUEST['windowf']);
              $cmd[]="teqc -phc -e ".date_format($end, 'YmdHis')." \$rinex_in > \"\$rinex_in.pos\"";
              $cmd[]="teqc -phc -st ".date_format($start, 'YmdHis')." \"\$rinex_pos.in\" > \$rinex_in";
              }
             * 
             */
            $start = new DateTime($_REQUEST['window0']);
            $end = new DateTime($_REQUEST['windowf']);
            $cmd[] = "teqc -phc -st " . date_format($start, 'YmdHis') .
                    " -e " . date_format($end, 'YmdHis') . " \$rinex_in > \"\$rinex_in.cut\"";
            $cmd[] = "cat \"\$rinex_in.cut\" > \$rinex_in";
        }

        $cmd[] = "rm -fv \"\$rinex_in.pre\" \"\$rinex_in.pos\" \"\$rinex_in.cut\"";



        $ret = implode($nl, $cmd);
        return $ret;
    }

//ok 
    function getBinaryFile() {

        //echo $this->getName()."/".$this->getYear()."/".$this->getDay();   
        //echo ftp_pwd($this->ftp_conn)." na conversao <br>";
        //ftp_set_option($this->ftp_conn, FTP_TIMEOUT_SEC, 600*2);
        //echo ftp_get_option ( $this->ftp_conn, FTP_TIMEOUT_SEC );

        if (ftp_chdir($this->ftp_conn, "/" . $this->getName() . "/" . $this->getYear() . "/" . $this->getDay())) {
            //ftp_pasv ($this->ftp_conn, true);
            if (ftp_get($this->ftp_conn, $this->full_rinex_path . $this->getFile(), $this->getFile(), FTP_BINARY)) {
                echo "Successfully copied {$this->getFile()}.";
            } else {
                echo "Error on downloading file {$this->getFile()}. ";
            }
            //back to ftp root
            ftp_cdup($this->ftp_conn);  
            ftp_cdup($this->ftp_conn);
            ftp_cdup($this->ftp_conn);
            //echo ftp_pwd($this->ftp_conn)." na conversao <br>";
        } else
            return false;
    }

//ok
    /**
     * Extract the filename into the path
     * @param String $filename the name for the file
     * @param String $string the contents for writing the file
     * @return Null just write the file in the log path
     * @author Bruno C. Vani
     */
    function extractRinexWin() {

        shell_exec("winrar x -y {$this->full_rinex_path}{$this->getFile()} $this->full_rinex_path");
        shell_exec("del {$this->full_rinex_path}{$this->getFile()}"); //delete the .gz file        

        $this->setFile(str_replace(".gz", '', $this->getFile())); //removes .gz after extraction
        // echo "<br>Successfully extracted: {$this->getFile()}.";

        return true;
    }

    /**
     * Extract the filename into the path
     * @param String $filename the name for the file
     * @param String $string the contents for writing the file
     * @return Null just write the file in the log path
     * @author Bruno C. Vani
     */
    function extractRinexLinux($name_only = false) {

        if (!$name_only) {
            shell_exec("gzip -d -v -f {$this->full_rinex_path}{$this->getFile()} ");
        }
        //shell_exec("gunzip -d -f {$this->full_rinex_path}{$this->getFile()} $this->full_rinex_path");         
        // shell_exec("rm {$this->full_rinex_path}{$this->getFile()}"); //delete the .gz file        

        $this->setFile(str_replace(".gz", '', $this->getFile())); //removes .gz after extraction
        // echo "<br>Successfully extracted: {$this->getFile()}.";

        return true;
    }

    
    /**
     * Function to merge raw binary files into a single raw file.
     * @param Array $list Array with the path of the raw files to be merged.
     * @param String $filename_final The desired filename of the output file
     */
    function mergeRawFiles($list, $filename_final){
        
        if(count($list)>0){
            $filestr = implode(" ", $list);
            $cmd = "cat $filestr > {$this->full_rinex_path}$filename_final";
            //echo "executing cmd $cmd";
            shell_exec($cmd);
            $cmd_rm = "rm $filestr";
            shell_exec($cmd_rm);
            
            $this->setFile($filename_final); //removes .gz after extraction
        }
        
    }
    
    
    function setReceiverParams() {
        $sql = "select id, name, antenna_number, antenna, x_, y_, z_ from station where name='{$this->getName()}'";

        $rs = pg_query($sql);
        if (pg_num_rows($rs)) {
            $ret = pg_fetch_all($rs);
        }
        $this->antenna = $ret[0]['antenna'];
        $this->antenna_number = $ret[0]['antenna_number'];
        $this->x = $ret[0]['x_'];
        $this->y = $ret[0]['y_'];
        $this->z = $ret[0]['z_'];
    }

    function deleteIntoRinex($filename, $so = null) {

        if (!$so)
            shell_exec("rm -f {$this->full_rinex_path}$filename");
        else
            del("del {$this->full_rinex_path}$filename");
    }

    /**
     * Return a str for excluding constellations during high rate conversion
     * @param integer $svid SVID indentification number of the satellite 
     * @return string The exclude clause to sbf2ismr
     * @author Bruno C. Vani (brunovani22@gmail.com)
     */
    public function getExclusionStr($svid) {
//Systems may be G (GPS), R (Glonass), E (Galileo), S (SBAS), C (Compass), J (QZSS) or any combination thereof. 
//For instance -xERSCJ produces a GPS-only observation file.

        if ($svid <= 37) {
            $excl = "-xERSCJ";
        } else if ($svid >= 38 && $svid <= 61) {
            $excl = "-xEGSCJ";
        } else if ($svid >= 71 && $svid <= 102) {
            $excl = "-xGRSCJ";
        } else if ($svid >= 120 && $svid <= 140) {
            $excl = "-xGRECJ";
        } else if ($svid >= 141 && $svid <= 172) {
            $excl = "-xGRESJ";
        } else if ($svid >= 181 && $svid <= 187) {
            $excl = "-xGRESC";
        }

        return $excl;
    }

    function sbfRawConversion($sat, $data_type, $mode = "txt", $includeTimestamp=false, $timestamp=NULL) {

        //prepare names
        $excl = $this->getExclusionStr($sat);
        $temp_name = $this->getFile() . ".raw"; // raw to text file
        $temp_name2  = $this->getFile() . ".svid_{$sat}_{$data_type}.txt.tmp"; // text file before pre-processing timestamp and labels
        $final_name = $this->getFile() . ".svid_{$sat}_{$data_type}.txt"; // final file
        
        //convert raw to text   
        $instr = "{$this->sbf2ismr_exe} -f {$this->full_rinex_path}{$this->getFile()} $excl -r {$this->full_rinex_path}{$temp_name}";
        shell_exec($instr);

        //extract obs
        $instr2 = "cat {$this->full_rinex_path}{$temp_name}| awk -F \",\" '$2==\"$sat\"' | awk -F \",\" '$3==\"$data_type\"' > {$this->full_rinex_path}{$temp_name2} ";
        shell_exec($instr2);
        
        // if necessary, append timestamp additional information
        if($includeTimestamp && $timestamp!=null){
            $names = "timestamp,wn,tow,svid,flag_obs_type,phase,Icorr,Qcorr\n";
            shell_exec("./serv_genTimestamp.R \"{$this->full_rinex_path}{$temp_name2}\" \"{$this->full_rinex_path}{$final_name}\" \"$timestamp\" ");            
        } else{
            $names = "tow,svid,flag_obs_type,phase,Icorr,Qcorr\n";
            $content = file_get_contents($this->full_rinex_path . $temp_name2);
            file_put_contents($this->full_rinex_path . $final_name,  $names. $content );            
        }
        
        //erase files
        $instr3 = "rm -v {$this->full_rinex_path}{$temp_name} {$this->full_rinex_path}{$temp_name2} {$this->full_rinex_path}*.ismr ";
        shell_exec($instr3);

        //echo "$instr <br> $instr2 <br> $instr3 <br>";
        //mode txt - display link to download ascii high rate file
        if ($mode == "txt") {
            echo "<hr>Download <a href='../rinex/tmp/{$final_name}' target='_blank' download>  $final_name  </a><hr>";
        } else if ($mode == "csv") {
            //echo $names;
            echo file_get_contents($this->full_rinex_path . $final_name);
        }
    }

    /**
     * Get the rinex for specific parameters. ex: source=PALM004T.12_ -->> dest=PALM004T.12O.
     * Filename details:
     * XXXXDOYS.YYO
     * XXXX = nome da estação (PRU2, algumas vezes vem o nome do receptor, SEPT)
     * DOY = day of year (001)
     * S = número da seção (pode ser a letra correspondente ao horário, no caso dos receptores da Septentrio)
     * YY = ano em dois dígitos (12)
     * O = arquivo de observação
     * @param String $filename the name of rinex file
     * @param String $version the version of the rinex file (2.11, 3, or 2.11c)
     * @param String $interval the time interval for the rinex file
     * @return String $destname the name of the rinex
     * @author Bruno C. Vani
     */
    function sbfConversion($version, $interval, $snr = false, $delete = true, $sufix = "0") {

        //convert to rinex        
        if ($snr) {
            $snr = "-s";
        } else {
            $snr = "";
        }

        $temp_name = str_replace("_", "_TEMP" . $sufix, $this->getFile()); //set the name of the observation file        

        $aux = "{$this->sbf2rin_exe} -f {$this->full_rinex_path}{$this->getFile()} -i $interval $version $snr -o {$this->full_rinex_path}{$temp_name}";
        //echo "\n<br>".$aux."\n<br>";
        shell_exec($aux);

        //set correct headers for rinex 
        $out_name = str_replace("_TEMP" . $sufix, "o", $temp_name);

        //with method - rinex 3
        if ($version === '-R3') {
            $this->changeRinex($temp_name, $out_name);
            //echo "manual change<br>";
        } else { //with teqc - rinex 2.11
            $this->changeRinexTeqc($temp_name, $out_name);
            //echo "teqc change<br>";
        }

        if ($delete) { //delete raw file
            
            // delete the raw file
            shell_exec("rm -f {$this->full_rinex_path}{$this->getFile()} ");
            //echo "<br> Succesfully converted: {$this->getFile()}.";
        }
        
        //delete the rinex temp file 
        shell_exec("rm -f {$this->full_rinex_path}{$temp_name}");


        $this->setFile($out_name);
        return true;
    }

    function sbfConversionTeqc($interval, $snr = false, $delete = true, $sufix = "P") {
        echo "converting..civil data only..<br>";

        //convert to rinex        
        if ($snr) {
            $snr = "-s"; //civil data and SNR civil only
            $obs_gfz = "G:C1C,L1C,C2L,L2L,C5Q,L5Q,S1C,S2C,S2L,S5Q";
        } else {
            $snr = ""; // civil data only
            $obs_gfz = "G:C1C,L1C,C2L,L2L,C5Q,L5Q";
        }


        $int_instr = "-i $interval";


        $temp_name = str_replace("_", "_TEMP" . $sufix, $this->getFile()); //set the name of the observation file        
        $temp_name2 = str_replace("_", "_TEMP2" . $sufix, $this->getFile()); //set the name of the observation file        
        // convert to RINEX3 to extract civil code and phases from R3
        $aux = "{$this->sbf2rin_exe} -R3 $int_instr $snr -f {$this->full_rinex_path}{$this->getFile()} -o {$this->full_rinex_path}{$temp_name} -v";
        //echo "\n".$aux."\n";
        shell_exec($aux);

        //teqc conversion to extract civil code (fail)
        //$aux="teqc -sep sbf -O.obs $obs $int_instr +svo {$this->full_rinex_path}{$this->getFile()} > {$this->full_rinex_path}{$temp_name} 2>/dev/null";        

        $aux2 = "gfzrnx_lx -finp {$this->full_rinex_path}{$temp_name} -fout {$this->full_rinex_path}{$temp_name2} -ot $obs_gfz --version_out 2";
        //echo "\n".$aux2."\n";
        shell_exec($aux2);

        //set correct headers for rinex 
        $out_name = str_replace("_TEMP2" . $sufix, "o", $temp_name2);

        //set header with teqc - rinex 2.11
        $this->changeRinexTeqc($temp_name2, $out_name);

        //delete the temp file 
        shell_exec("rm -f {$this->full_rinex_path}{$temp_name}");
        shell_exec("rm -f {$this->full_rinex_path}{$temp_name2}");

        if ($delete) { //delete raw file
            shell_exec("rm -f {$this->full_rinex_path}{$this->getFile()} ");
        }

        $this->setFile($out_name);
        return true;
    }

    /**
     * This function create rinex v3.x from sbf files. The files are manipulated to setup headers 
     * and later compressed via Hatanaka. 
     * Obs: internal call for rinex service.
     * @param type $delete
     */
    function sbfConversion3Service($delete = true) {

        //$tmp_name = substr($this->getFile(). md5(uniqid(time())),0,16).".raw";
        $out_name = substr($this->getFile() . md5(uniqid(time())), 0, 16) . ".rnx";

        if ($delete) {
            $del = "rm -v {$this->getFile()} ";
        } else {
            $del = "";
        }

        $instr = "cd {$this->full_rinex_path}; sbf2rin -f {$this->getFile()} -i 1 -R3 -s -D -X -l -O BRA -o $out_name; $del";
        echo "Instr: $instr\n";
        shell_exec($instr);

        if (!filesize($this->full_rinex_path . $out_name)) {
            echo "\nError: null rinex 3. \n";
        } else {
            echo "\nSuccesfully converted $out_name, renaming..\n";

            $line_first = shell_exec("cat {$this->full_rinex_path}$out_name | grep \"FIRST OBS\"");
            //echo "line first:  $line_first \n";
            //get file time of first obs
            $station = substr($out_name, 0, 4);
            $doy = substr($out_name, 4, 3);
            $itens = preg_split("/[ \t]+/", $line_first);
            $year = $itens[1];
            $hour = str_pad($itens[4], 2, "0", STR_PAD_LEFT);
            $minute = str_pad($itens[5], 2, "0", STR_PAD_LEFT);

            $final_name = $station . "00BRA_R_" . $year . $doy . $hour . $minute . "_01H_01S_MO.rnx";

            //final rnx name and header
            $this->changeRinex($out_name, $final_name);
            //echo shell_exec("mv -v {$this->full_rinex_path}$out_name {$this->full_rinex_path}$final_name");
            //hatanaka compression
            echo shell_exec("RNX2CRZ -d -g -f {$this->full_rinex_path}$final_name");
            $hata_name = str_replace(".rnx", ".crx.gz", $final_name);

            if ($delete) {
                shell_exec("rm -v {$this->full_rinex_path}$out_name");
            }

            //print_r($itens);

            echo "hatanaka name: \n$hata_name\n";
            $this->setFile($hata_name);
        }
    }

    function zipRinexs($rinex_list) {

        $rinex_list_impl = implode(" ", $rinex_list);

        $yy = substr($this->getDay(), 0, 2);

        $doy = substr($this->getDay(), 2);

        $zipname = "{$this->getName()}{$doy}0.{$yy}d.zip";
        echo $instr = "cd {$this->full_rinex_path}; zip -m $zipname $rinex_list_impl";

        shell_exec($instr);

        if (!filesize($this->full_rinex_path . $zipname)) {
            echo "\nError: null rinex 3. \n";
        } else {
            echo "\nSuccesfully converted 3 - $zipname\n";
        }//

        echo "final zip name: \n$zipname\n";

        $this->setFile($zipname);
    }

    function sbfConversionMerge3($rinex_list, $delete = true) {

        $tmp_name = substr($rinex_list[0] . md5(uniqid(time())), 0, 16) . ".raw";
        $out_name = substr($rinex_list[0] . md5(uniqid(time())), 0, 16) . ".rnx";

        $rinex_list_impl = implode(" ", $rinex_list);

        if ($delete) {
            $del = "rm -v $rinex_list_impl ";
        } else {
            $del = "";
        }

        $instr = "cd {$this->full_rinex_path}; cat $rinex_list_impl > $tmp_name; sbf2rin -f $tmp_name -i 1 -R3 -s -D -l -O BRA -o $out_name; $del";
        //echo "Instr: $instr\n";
        shell_exec($instr);

        if (!filesize($this->full_rinex_path . $out_name)) {
            echo "\nError: null rinex 3. \n";
        } else {
            echo "\nSuccesfully converted $out_name, renaming..\n";

            $line_first = shell_exec("cat {$this->full_rinex_path}$out_name | grep \"FIRST OBS\"");
            //echo "line first:  $line_first \n";

            $station = substr($rinex_list[0], 0, 4);
            $doy = substr($rinex_list[0], 4, 3);
            $itens = preg_split("/[ \t]+/", $line_first);
            $year = $itens[1];
            $hour = str_pad($itens[4], 2, "0", STR_PAD_LEFT);
            $minute = str_pad($itens[5], 2, "0", STR_PAD_LEFT);

            $final_name = $station . "00BRA_R_" . $year . $doy . $hour . $minute . "_01D_01S_MO.rnx";

            //final rnx name and header
            $this->changeRinex($out_name, $final_name);
            //echo shell_exec("mv -v {$this->full_rinex_path}$out_name {$this->full_rinex_path}$final_name");
            //hatanaka compression
            echo shell_exec("RNX2CRZ -d -g -f {$this->full_rinex_path}$final_name");
            $hata_name = str_replace(".rnx", ".crx.gz", $final_name);

            if ($delete) {
                shell_exec("rm -v {$this->full_rinex_path}$tmp_name {$this->full_rinex_path}$out_name");
            }

            //print_r($itens);

            echo "hatanaka name: \n$hata_name\n";
            $this->setFile($hata_name);
        }
    }

    function changeRinexTeqc($temp_name, $out_name) {
        if (!$this->x) {
            $this->x = 0;
        }
        if (!$this->y) {
            $this->y = 0;
        }
        if (!$this->z) {
            $this->z = 0;
        }

        $aux = "teqc +O.c \"ISMR Query Tool - FCT UNESP - GEGE - " . date("F j, Y") . "\" " .
                "-O.mo {$this->getName()} -O.mn 0000 -O.o LGE -O.ag \"FCT UNESP\" -O.rt \"SEPT POLARXS\" -O.an \"{$this->antenna_number}\" " .
                "-O.at \"{$this->antenna}\" -O.px {$this->x} {$this->y} " .
                "{$this->z} {$this->full_rinex_path}{$temp_name} > {$this->full_rinex_path}{$out_name} ";
        //echo "\n<br>".$aux."\n<br>";
        echo shell_exec($aux);
    }

    public function changeRinex($temp_name, $out_name) {

        $fp = @fopen($this->full_rinex_path . $temp_name, 'r');
        $fp_o = @fopen($this->full_rinex_path . $out_name, 'w');
        if ($fp && $fp_o) {

            //for($i=0; $i<10; $i++){                                 
            while (($line = fgets($fp)) && (!preg_match('/END OF HEADER/', $line))) {

                if (preg_match('/MARKER NAME/', $line)) {
                    $marker_name = str_pad($this->getName(), 60) . "MARKER NAME\n";
                    fwrite($fp_o, $marker_name);
                } else if (preg_match('/INTERVAL/', $line)) {
                    fwrite($fp_o, $line);
                    $comment = str_pad("ISMR Query Tool - FCT UNESP - GEGE - " . date("F j, Y"), 60) . "COMMENT\n";
                    fwrite($fp_o, $comment);
                } else if (preg_match("/MARKER NUMBER/", $line)) {
                    $marker_number = str_pad("0000", 60) . "MARKER NUMBER\n";
                    fwrite($fp_o, $marker_number);
                } else if (preg_match("/AGENCY/", $line)) { //observer / agency                   
                    $observer_agency = str_pad("LGE", 20) . str_pad("FCT UNESP", 40) . "OBSERVER / AGENCY\n";
                    fwrite($fp_o, $observer_agency);
                } else if (preg_match("/ANT # \/ TYPE/", $line)) { //antenna / type                   
                    $antenna_type = str_pad($this->antenna_number, 20) . str_pad($this->antenna, 40) . "ANT # / TYPE\n";
                    fwrite($fp_o, $antenna_type);

                    //set approx position
                    $xs = sprintf("%14.4f", $this->x);
                    $ys = sprintf("%14.4f", $this->y);
                    $zs = sprintf("%14.4f", $this->z);
                    echo $approx_position = str_pad($xs . "" . $ys . "" . $zs, 60) . "APPROX POSITION XYZ\n";
                    fwrite($fp_o, $approx_position);
                } else if (preg_match("/REC # \/ TYPE \/ VERS/", $line)) { //receiver, type, version (software)
                    $rec_type_version = substr($line, 0, 20) . str_pad("SEPT POLARXS", 20) . substr($line, 40, 60);
                    fwrite($fp_o, $rec_type_version);
                } else if (preg_match("/APPROX POSITION XYZ/", $line)) { //antenna / type                   
                    //do nothing
                } else
                    fwrite($fp_o, $line);
            }

            fwrite($fp_o, str_pad(" ", 60) . "END OF HEADER\n");

            while ($line = fgets($fp)) {
                fwrite($fp_o, $line);
            }

            fclose($fp);
            fclose($fp_o);
        } else {
            echo "Error while opening files.<br>";
        }
    }

    //ok
    function mergeRinexTeqc($rinex, $gzip = true, $prefix = "0") {
        // echo "<br>";        
        //echo "teqc merge<br>";        
        $all = '';

        foreach ($rinex as $r) {
            if (!filesize($this->full_rinex_path . $r)) {
                echo "Corrupted intermediate file discarded: $r.<br>\n";
            } else
                $all .= $this->full_rinex_path . $r . " ";
        }

        if (strlen($this->getFile()) >= 14) {
            $out_name = substr($this->getFile(), 0, 7) . $prefix . substr($this->getFile(), 10, 13);
        } else {
            $out_name = substr($this->getFile(), 0, 7) . $prefix . substr($this->getFile(), 8);
        }
        $aux = "teqc {$all} > {$this->full_rinex_path}$out_name";
        echo shell_exec($aux);

        shell_exec("rm -f $all");                

        if ($gzip) {
            shell_exec("gzip -f {$this->full_rinex_path}$out_name");
            $out_name .= ".gz";
        }

        $this->setFile($out_name);
    }

    function mergeRinex($rinex) {

        $message = "";

        $out_name = substr($this->getFile(), 0, 7) . "1" . substr($this->getFile(), 8); //1 to session
        $fp_o = @fopen($this->full_rinex_path . $out_name, 'w');

        //$rinex[count($rinex)-1]="lala";        
        //get the last time obs
        $fpn = @fopen($this->full_rinex_path . $rinex[count($rinex) - 1], 'r');
        if ($fpn) {
            while (($line = fgets($fpn)) && (!preg_match("/END OF HEADER/", $line))) {
                if (preg_match("/TIME OF LAST OBS/", $line)) {
                    $last_obs = $line;
                    break;
                }
            }
            fclose($fpn);
        } else {
            $message .= "<b>Warning:</b> can't read the last file of the day.<br>";
        }

        if (!$last_obs)
            $message .= "<b>Warning:</b> can't read the TIME OF LAST OBS in the last file. Please, check it.<br>";

        //copyng header from the first file, and setting time of last obs of last file
        $fp1 = @fopen($this->full_rinex_path . $rinex[0], 'r');
        if ($fp1) {
            while (($line = fgets($fp1)) && (!preg_match("/END OF HEADER/", $line))) {

                if (preg_match("/TIME OF LAST OBS/", $line) && $last_obs) {
                    fwrite($fp_o, $last_obs);
                    $edit_last_obs = true;
                } else {
                    fwrite($fp_o, $line); //editar tempo da ultima obs   
                }
            }
            fwrite($fp_o, $line); //write end of header
            while ($line = fgets($fp1))
                fwrite($fp_o, $line); //     append observations                
            fclose($fp1);
            shell_exec("rm -f {$this->full_rinex_path}{$rinex[0]} ");
        } else {
            $message .= "<b>Error:</b> error on creation.<br>";
        }

        if (!$edit_last_obs)
            $message = "<b>Warning:</b> can't change the TIME OF LAST OBS in the out file. Please, check it manually.<br>" . $message;


        //append more files without the header
        for ($i = 1; $i < count($rinex); $i++) {
            $fp = @fopen($this->full_rinex_path . $rinex[$i], 'r');
            if ($fp) {
                while (($line = fgets($fp)) && (!preg_match("/END OF HEADER/", $line))); //read until end of header                                 
                while ($line = fgets($fp)) { //read until end of file
                    fwrite($fp_o, $line);
                }
                fclose($fp);
            }

            shell_exec("rm -f {$this->full_rinex_path}{$rinex[$i]} ");
        }
        //$all=implode(" ", $rinex);  
        fclose($fp_o);

        $this->setFile($out_name);

        return $message;
    }

    /**
     * Copy the binary file for the full ftp URL
     * @param String $remote_url the url from ftp     
     * @return The name of the downloaded file
     * @author Bruno C. Vani
     */
    /*
      function copyFileFromUrl($remote_url){

      $remote_url = str_replace("ftp://{$this->ftp_host}/", "", $remote_url);
      $filename=split("/", $remote_url);
      $filename=$filename[count($filename)-1]; //gets just the name of file

      // try to download
      if (ftp_get($this->ftp_conn, $this->full_rinex_path.$filename, $remote_url, FTP_BINARY)) {
      echo "Successfully copied.";
      } else {
      echo "Error on download binary file. Check if URL is correct.<br>";
      return false;
      }
      return $filename;
      }


      /*download file and copy to tmp directory */
    /*
      function copyFile(){
      // try to download $server_file and save to $local_file
      if (ftp_get($this->ftp_conn, "temp/".$this->file,
      $this->name."/".$this->year."/".$this->day."/".$file, FTP_BINARY)) {
      echo "Successfully written";
      } else {
      echo "There was a problem\n";
      }
      }


      function setRinexFile($url, $interval, $version ){

      //get the binary gz
      $binary_gz=$this->copyFileFromUrl($url);

      //extract the zip file
      $binary=$this->extractRinexWin($binary_gz);

      //get the rinex file and its respective filename from binary
      $rinex=$this->getRinexWin($binary, $version, $interval);
      $this->filename=$rinex;
      return true;
      }

     */
    /*
      //checa se encontrou?
      if(!$marker_name){
      echo "encontrou 6<br>";
      $marker_name=str_pad($this->getName(), 60)."MARKER NAME\n";
      fwrite($fp_o, $marker_name);
      }
      else if(!$marker_number ){
      echo "encontrou 7<br>";
      $marker_number=str_pad("0000", 60)."MARKER NUMBER\n";
      fwrite($fp_o, $marker_number);
      }
      else if(!$observer_agency){ //observer / agency
      echo "encontrou 8<br>";
      $observer_agency=str_pad("LGE", 20).str_pad("FCT UNESP", 40)."OBSERVER / AGENCY\n";
      fwrite($fp_o, $observer_agency);
      }
      else if(!$antenna_type){ //antenna / type
      $antenna_type=str_pad($this->antenna_number, 20).str_pad($this->antenna, 40)."ANT # / TYPE\n";
      fwrite($fp_o, $antenna_type);
      }
      else if(!$approx_position){ //aprox
      echo "encontrou 10<br>";
      $approx_position=str_pad($this->x." ".$this->y." ".$this->z, 60)."APPROX POSITION XYZ\n";
      fwrite($fp_o, $approx_position);
      } */
}

?>
