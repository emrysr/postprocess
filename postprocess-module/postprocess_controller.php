<?php

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

function postprocess_controller()
{
    global $log,$homedir,$session,$route,$mysqli,$redis,$feed_settings;
    if (!isset($homedir)) $homedir = "/home/pi";
    
    $result = false;
    $route->format = "text";


    include "Modules/postprocess/postprocess_model.php";
    $postprocess = new PostProcess($mysqli);

    include "Modules/feed/feed_model.php";
    $feed = new Feed($mysqli,$redis,$feed_settings);
    
    $processes = array(
        "powertokwh"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:")
        ),
        "accumulator"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:")
        ),
        "importcalc"=>array(
            "consumption"=>array("type"=>"feed", "engine"=>5, "short"=>"Select consumption power feed:"),
            "generation"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar generation power feed:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter import feed name:", "nameappend"=>"")
        ),
        "exportcalc"=>array(
            "generation"=>array("type"=>"feed", "engine"=>5, "short"=>"Select solar generation power feed:"),
            "consumption"=>array("type"=>"feed", "engine"=>5, "short"=>"Select consumption power feed:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter export feed name:", "nameappend"=>"")
        ),
        "addfeeds"=>array(
            "feedA"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed A:"),
            "feedB"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed B:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
        ),
        "scalefeed"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed to scale:"),
            "scale"=>array("type"=>"value", "short"=>"Scale by:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
        ),
        "offsetfeed"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed to apply offset:"),
            "offset"=>array("type"=>"value", "short"=>"Offset by:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
        ),
        "mergefeeds"=>array(
            "feedA"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed A:"),
            "feedB"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed B:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
        ),
        "trimfeedstart"=>array(
            "feedid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to trim:"),
            "trimtime"=>array("type"=>"value", "short"=>"Enter start time to trim from:")
        ),
        "removeresets"=>array(
            "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input feed:"),
            "maxrate"=>array("type"=>"value", "short"=>"Max accumulation rate:"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:")
        ),
        "liquidorairflow_tokwh"=>array(
            "vhc"=>array("type"=>"value", "short"=>"volumetric heat capacity in Wh/m3/K"),
            "flow"=>array("type"=>"feed", "engine"=>5, "short"=>"flow in m3/h"),
            "tint"=>array("type"=>"feed", "engine"=>5, "short"=>"Internal temperature feed / start temperature feed :"),
            "text"=>array("type"=>"feed", "engine"=>5, "short"=>"External temperature feed / return temperature feed :"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name for permeability losses in m3/h :")
        ),
        "constantflow_tokwh"=>array(
            "vhc"=>array("type"=>"value", "short"=>"volumetric heat capacity in Wh/m3/K"),
            "flow"=>array("type"=>"value", "short"=>"constant flow in m3/h"),
            "tint"=>array("type"=>"feed", "engine"=>5, "short"=>"Internal temperature feed / start temperature feed :"),
            "text"=>array("type"=>"feed", "engine"=>5, "short"=>"External temperature feed / return temperature feed :"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name for permeability losses in m3/h :")
        ),
        "basic_formula"=>array(
            "formula"=>array("type"=>"formula", "short"=>"Enter your formula (e.g. f1+2*f2-f3/12 if you work on feeds 1,2,3) - brackets not implemented"),
            "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name :")
        )
    );

    // -------------------------------------------------------------------------
    // VIEW
    // -------------------------------------------------------------------------
    if ($route->action == "" && $session['write']) {
        $result = view("Modules/postprocess/view.php",array());
        $route->format = "html";
        return array('content'=>$result);
    }

    if ($route->action == "processes" && $session['write']) {
        $route->format = "json";
        return array('content'=>$processes);
    }

    // -------------------------------------------------------------------------
    // PROCESS LIST
    // -------------------------------------------------------------------------    
    if ($route->action == "list" && $session['write']) {
        
        $userid = $session['userid'];
        
        $processlist = $postprocess->get($userid);
        
        if ($processlist==null) $processlist = array();
        $processlist_long = array();
        $processlist_valid = array();
        
        for ($i=0; $i<count($processlist); $i++) {
            $valid = true;
            
            $item = json_decode(json_encode($processlist[$i]));
            
            $process = $item->process;
            if (isset($processes[$process])) {
                foreach ($processes[$process] as $key=>$option) 
                {
                    if ($option['type']=="feed" || $option['type']=="newfeed") {
                        $id = $processlist[$i]->$key;
                        if ($feed->exist((int)$id)) {
                            $f = $feed->get($id);
                            if ($f['userid']!=$session['userid']) return false;
                            if ($meta = $feed->get_meta($id)) {
                                $f['start_time'] = $meta->start_time;
                                $f['interval'] = $meta->interval;
                                $f['npoints'] = $meta->npoints;
                                $f['id'] = (int) $f['id'];
                                $timevalue = $feed->get_timevalue($id);
                                $f['time'] = $timevalue["time"];
                            } else {
                                $valid = false;
                            }
                            $item->$key = $f;
                        } else {
                            $valid = false;
                        }
                    }
                    
                    if ($option['type']=="formula"){
                        $formula=$processlist[$i]->$key;
                        $f=array();
                        $f['expression']=$formula;
                        //we catch feed numbers in the formula
                        $feed_ids=array();
                        while(preg_match("/(f\d+)/",$formula,$b)){
                            $feed_ids[]=substr($b[0],1,strlen($b[0])-1);
                            $formula=str_replace($b[0],"",$formula);
                        }
                        $all_intervals=array();
                        $all_start_times=array();
                        $all_ending_times=array();
                        //we check feeds existence and stores all usefull metas
                        foreach($feed_ids as $id) {
                            if ($feed->exist((int)$id)){
                                $m=$feed->get_meta($id);
                                $all_intervals[]=$m->interval;
                                $all_start_times[]=$m->start_time;
                                $timevalue = $feed->get_timevalue($id);
                                $all_ending_times[] = $timevalue["time"];
                            } else {
                                $valid = false;
                            }
                        }
                        if ($valid){
                            $f['interval'] = max($all_intervals);
                            $f['start_time']= max($all_start_times);
                            $f['time']= min($all_ending_times);
                            
                            $item->$key = $f;
                        }
                    }
                }
            } else {
                $valid = false;
            }
            
            if ($valid) { 
                $processlist_long[] = $item;
                $processlist_valid[] = $processlist[$i];
            }
        }
        
        $postprocess->set($userid,$processlist_valid);
        
        $result = $processlist_long;
    
        $route->format = "json";
    }

    // -------------------------------------------------------------------------
    // CREATE NEW
    // -------------------------------------------------------------------------
    if ($route->action == "create" && $session['write']) {
        $route->format = "text";

        if (!isset($_GET['process'])) 
            return array('content'=>"expecting parameter process");
            
        $process = $_GET['process'];
        $params = json_decode(file_get_contents('php://input'));
       
        foreach ($processes[$process] as $key=>$option) {
           if (!isset($params->$key)) 
               return array('content'=>"missing option $key");
           
           if ($option['type']=="feed") {
               $feedid = (int) $params->$key;
               if ($feedid<1)
                   return array('content'=>"feed id must be numeric and more than 0");
               if (!$feed->exist($feedid)) 
                   return array('content'=>"feed does not exist");
               $f = $feed->get($feedid);
               if ($f['userid']!=$session['userid']) 
                   return array('content'=>"invalid feed");
               if ($f['engine']!=$option['engine']) 
                   return array('content'=>"incorrect feed engine");
               
               $params->$key = $feedid;
           }
           
           if ($option['type']=="newfeed") {
               $newfeedname = preg_replace('/[^\w\s-:]/','',$params->$key);
               if ($params->$key=="")
                   return array('content'=>"new feed name is blank");
               if ($newfeedname!=$params->$key)
                   return array('content'=>"new feed name contains invalid characters");
                if ($feed->get_id($session['userid'],$newfeedname)) 
                   return array('content'=>"feed already exists with name $newfeedname");
                   
                // New feed creation: note interval is 3600 this will be changed by the process to match input feeds..
                $c = $feed->create($session['userid'],"",$newfeedname,DataType::REALTIME,Engine::PHPFINA,json_decode('{"interval":3600}'));
                if (!$c['success'])
                    return array('content'=>"feed could not be created");
                    
                // replace new feed name with its id if successfully created
                $params->$key = $c['feedid'];
           }
           
           if ($option['type']=="value") {
               $value = (float) 1*$params->$key;
               if ($value!=$params->$key)
                   return array('content'=>"invalid value");
           }
        }
        
        // If we got this far the input parameters where valid.
        
        $userid = $session['userid'];
        $processlist = $postprocess->get($userid);
        if ($processlist==null) $processlist = array();
        
        $params->process = $process;
        $processlist[] = $params;
        
        $postprocess->set($userid,$processlist);
        $redis->lpush("postprocessqueue",json_encode($params));
        
         // -----------------------------------------------------------------
        // Run postprocessor script using the emonpi service-runner
        // -----------------------------------------------------------------        
        $update_script = "$homedir/postprocess/postprocess.sh";
        $update_logfile = "$homedir/data/postprocess.log";
        $redis->rpush("service-runner","$update_script>$update_logfile");
        $result = "service-runner trigger sent";
        // -----------------------------------------------------------------
        
        $route->format = "json";
        return array('content'=>$params);
    }

    // -------------------------------------------------------------------------
    // UPDATE
    // -------------------------------------------------------------------------
    if ($route->action == "update" && $session['write']) {
        $route->format = "text";
        
        if (!isset($_GET['process'])) 
            return array('content'=>"expecting parameter process");
            
        $process = $_GET['process'];
        $params = json_decode(file_get_contents('php://input'));

        foreach ($processes[$process] as $key=>$option) {
           if (!isset($params->$key)) 
               return array('content'=>"missing option $key");
           
           if ($option['type']=="feed" || $option['type']=="newfeed") {
               $feedid = (int) $params->$key;
               if ($feedid<1)
                   return array('content'=>"feed id must be numeric and more than 0");
               if (!$feed->exist($feedid)) 
                   return array('content'=>"feed does not exist");
               $f = $feed->get($feedid);
               if ($f['userid']!=$session['userid']) 
                   return array('content'=>"invalid feed");
               if ($f['engine']!=$option['engine']) 
                   return array('content'=>"incorrect feed engine");
           }
           
           if ($option['type']=="value") {
               $value = (float) $params->$key;
               if ($value!=$params->$key)
                   return array('content'=>"invalid value");
           }
        }
        
        // If we got this far the input parameters where valid.
        
        $userid = $session['userid'];
        $processlist = $postprocess->get($userid);
        if ($processlist==null) $processlist = array();
        
        $params->process = $process;
        // Check to see if the process has already been registered
        $valid = false;
        for ($i=0; $i<count($processlist); $i++) {
            $tmp = $processlist[$i];
            // print "prm:".json_encode($params)."\n";
            // print "tmp:".json_encode($tmp)."\n";
            if (json_encode($tmp)==json_encode($params)) $valid = true;
        }
        if (!$valid) 
            return array('content'=>"process does not exist, please create");
        
        // Add process to queue
        $redis->lpush("postprocessqueue",json_encode($params));
        
        // -----------------------------------------------------------------
        // Run postprocessor script using the emonpi service-runner
        // -----------------------------------------------------------------
        $update_script = "$homedir/postprocess/postprocess.sh";
        $update_logfile = "$homedir/data/postprocess.log";
        $redis->rpush("service-runner","$update_script>$update_logfile");
        $result = "service-runner trigger sent";
        // -----------------------------------------------------------------
        
        $route->format = "json";
        return array('content'=>$params);
    }
    
    if ($route->action == 'getlog') {
        $route->format = "text";
        $log_filename = "$homedir/data/postprocess.log";
        if (file_exists($log_filename)) {
          ob_start();
          $handle = fopen($log_filename, "r");
          $lines = 200;
          $linecounter = $lines;
          $pos = -2;
          $beginning = false;
          $text = array();
          while ($linecounter > 0) {
            $t = " ";
            while ($t != "\n") {
              if(!empty($handle) && fseek($handle, $pos, SEEK_END) == -1) {
                $beginning = true;
                break;
              }
              if(!empty($handle)) $t = fgetc($handle);
              $pos --;
            }
            $linecounter --;
            if ($beginning) {
              rewind($handle);
            }
            $text[$lines-$linecounter-1] = fgets($handle);
            if ($beginning) break;
          }
          foreach (array_reverse($text) as $line) {
            echo $line;
          }
          $result = trim(ob_get_clean());
        } else $result="no logging yet available";
    }
    
    return array('content'=>$result, 'fullwidth'=>false);
}
