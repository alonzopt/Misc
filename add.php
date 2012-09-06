<?php
global $user;
//make sure an argument was passed
if(!empty($_GET)){
	
	/**
	*Argument reference:
	* $_GET may hold the following arguments
	* v (value) - int, should correspond to a node ID.
	* l (list number) - int, valid range 1-x (where x is the highest cell allowed in an array), used to determine which cell from $cur_data is to be used
	* bulkrep (bulk replace) - string of ints, comma separated. Will be validated and then replace the current contents of the list specified by l. Has priority over bulkadd.
	* bulkadd (bulk add) - string of ints, comma separated. Will be validated and then added to the current content of the list specified by l. Has priority over v.
	* lname (list name) - string, the name to set for the list specified by l
	* p (privacy setting) - ***CURRENTLY UNIMPLEMENTED*** int, range 0-2, used to set the privacy level of a list
	*	0 - private, only viewable to the owner (default)
	*	1 - protected, i.e. friends only (requires implementation of friend system)
	*	2 - public, anyone with a Cru account can view if given the address
	*
	* Dependancies:
	*	v may be passed alone
	*	l must be passed with v, bulkrep, bulkadd, or lname argument
	*	bulkrep, bulkadd, and lname will not be processed without being passed with a valid value for l
	*/
	
	/**validate argument;
	  *make sure an int
	  *make sure a pairing (database call)
	*/
	if($_GET['v']){
		$v_arg = intval($_GET['v']);
	}
	else{
		$v_arg = FALSE;
	}
	
	if($_GET['l']){
		$l_arg = intval($_GET['l']);
	}
	else{
		$l_arg = FALSE;
	}
	
	
	//used to determine if there will be a final writing to the database
	$query_new = FALSE;
	
	//handle list related functions
	if($l_arg > 0){
		echo "uid is $user->uid, l is $l_arg";
		
		//database call to collect user's CruQ data
		$query = "SELECT cruq_list_data FROM {cruq} WHERE uid=". $user->uid;
		$result = db_query($query);
		
		$cur_cruq = db_fetch_object($result);
		//$cur_cruq==FALSE if the user's uid is not in the cruq database
		
		if($cur_cruq){
			//has existing data
			$cur_data = unserialize(base64_decode($cur_cruq->cruq_list_data));
			echo "<br />Current Data: " . json_encode($cur_data) . "<br />";
			
			//Check that $l_arg is modifying an existing list or needs to create a new one
			if(isset($cur_data[$l_arg])){
				//modify an existing list
				if(strlen($_GET['lname']) > 0 || strlen($_GET['bulkrep']) > 0 || strlen($_GET['bulkadd']) > 0 || $v_arg > 0){
					//will hold any changes that need to be made to $cur_data
					$changes_array = array();
					
					//handle possible name change
					if(strlen($_GET['lname']) > 0){
						//get a safe UTF-8 encoded string, can return an empty string if too unsafe
						$lname = check_plain($_GET['lname']);
						
						//if the safe string is not empty, and the new name is not the same as the current name
						if(strlen($lname) > 0 && strcmp($lname,$cur_data[$l_arg][0]) != 0){
							$changes_array['lname'] = $lname;
						}
					}
					
					//handle replacement of current values via bulkrep
					if(strlen($_GET['bulkrep']) > 0){
						echo "<p>entering bulkrep processing</p>".PHP_EOL;
						//Process bulkrep
						//split into array
						$bulkvals = explode(",",$_GET['bulkrep']);
						//remove non-int values
						array_walk($bulkvals, 'intval');
						$ints = array_unique($bulkvals);
						
						//form db query to check the node table (looking for a node of type "pairing" with nids found in $ints)
						$query = "SELECT nid FROM {node} WHERE nid IN (".implode(",",$ints).") AND type IN ('pairing','testpairing')";
						$result = db_query($query);
						
						//initialize and populate an array with the valid values found in the database
						$result_array = array();
						while($cur = db_fetch_object($result)){
							$result_array[] = $cur->nid;
						}

						//remove invalid values, if any
						$checked = array_intersect($ints,$result_array);
						echo "<p>ints: ".json_encode($ints)."</p>";
						echo "<p>result array: ".json_encode($result_array)."</p>";
						echo "<p>checked: ".json_encode($checked)."</p>";
						//if there are valid values, prep it to be changed in $cur_data
						if(count($checked) > 0){
							$changes_array['bulkvals'] = implode(",", $checked);
						}
					}
					//handle addition to current values via bulkadd
					elseif(strlen($_GET['bulkadd']) > 0){
						echo "<p>entering bulkadd preocessing</p>".PHP_EOL;
						//Process bulkadd
						//split into array
						$bulkvals = explode(",",$_GET['bulkadd']);
						//remove non-int values
						array_walk($bulkvals, 'intval');
						$ints = array_unique($bulkvals);
						
						//form db query to check the node table (looking for a node of type "pairing" with nids found in $ints)
						$query = "SELECT nid FROM {node} WHERE nid IN (".implode(",",$ints).") AND type IN ('pairing','testpairing')";
						$result = db_query($query);
						
						//initialize and populate an array with the valid values found in the database
						$result_array = array();
						while($cur = db_fetch_object($result)){
							$result_array[] = $cur->nid;
						}
						
						//get current list data
						$cur_vals = explode(",",$cur_data[$l_arg][1]);
						//remove values that would be duplicates
						$ints = array_diff($ints,$cur_vals);
						//remove invalid node ids
						$checked = array_intersect($ints,$result_array);
						//get result array
						$to_add = array_merge($cur_vals,$ints);
						echo "<p>ints: ".json_encode($ints)."</p>";
						echo "<p>result array: ".json_encode($result_array)."</p>";
						echo "<p>checked: ".json_encode($checked)."</p>";
						echo "<p>to_add: ".json_encode($to_add)."</p>";
						//if there are valid values, prep it to be changed in $cur_data
						if(count($checked) > 0){
							$changes_array['bulkvals'] = implode(",", $to_add);
						}
					}
					//v_arg (only if !bulkvals)
					elseif($v_arg > 0){
						echo "<p>entering v processing</p>".PHP_EOL;
						//form db query to check the node table (looking for a node of type "pairing" with nid==$v_arg)
						$query = "SELECT nid FROM {node} WHERE type IN ('pairing','testpairing') AND nid='" . $v_arg . "'";
						$result = db_query($query);
						$valid = db_fetch_object($result);
						
						if(strlen($cur_data[$l_arg][1])==0){
							$cur = array();
						}
						else{
							$cur = explode(",",$cur_data[$l_arg][1]);
						}
						
						//if the nid held in $v_arg is a valid pairing and is not already in the list, add it
						if($valid && !in_array($v_arg,$cur)){
							$cur[] = $v_arg;
							$changes_array['bulkvals'] = implode(",",$cur);
						}
					}

					echo "<p>changes_array: ".json_encode($changes_array)."</p>";
					//if anything in $cur_data[$l_arg] needs to be changed
					if(count($changes_array) > 0){
						//change the name if needed
						if(isset($changes_array['lname'])){
							$cur_data[$l_arg][0] = $changes_array['lname'];
						}
						//change the sorted values if needed
						if(isset($changes_array['bulkvals'])){
							$cur_data[$l_arg][1] = $changes_array['bulkvals'];
						}
						
						echo "<br />New Data: " . json_encode($cur_data). "<br />";

						$updated_data = base64_encode(serialize($cur_data));

						$query="UPDATE {cruq} SET cruq_list_data='" . $updated_data . "', last_update=NOW() WHERE uid=". $user->uid;
						$query_new = TRUE;
					}
					else{
						echo "Error: Reached end of the modify existing list process with no valid changes.<br />";
						
					}
				}
				//missing one or more necessary arguments
				else{
					echo "<br />Error: missing one or more necessary arguments to modify list $l_arg.";
				}
			}
			//$cur_data[$l_arg] is not currently set
			else{
				//new list
				if($l_arg == count($cur_data) && strlen($_GET['lname']) > 0 && strlen($_GET['bulkadd']) > 0){
					
					//Process lname
					$lname = check_plain($_GET['lname']);
					
					//Process bulkvals
					//split into array
					$bulkvals = explode(",",$_GET['bulkadd']);
					//remove non-int values
					array_walk($bulkvals, 'intval');
					$ints = array_unique($bulkvals);
					
					//form db query to check the node table (looking for a node of type "pairing" with nids found in $ints)
					$query = "SELECT nid FROM {node} WHERE nid IN (".implode(",",$ints).") AND type IN ('pairing','testpairing')";
					$result = db_query($query);
					
					//initialize and populate an array with the valid values found in the database
					$result_array = array();
					while($cur = db_fetch_object($result)){
						$result_array[] = $cur->nid;
					}
					
					//remove invalid values, if any
					$checked = array_intersect($ints,$result_array);
					echo "<p>ints: ".json_encode($ints)."</p>";
					echo "<p>result array: ".json_encode($result_array)."</p>";
					
					//prepares to add list to the db if the name and contents are valid
					if(strlen($lname) > 0 && count($checked) > 0){
						$cur_data[$l_arg] = array($lname,implode(",",$checked),0);
						echo "<br />New Data: " . json_encode($cur_data). "<br />";
						$updated_data = base64_encode(serialize($cur_data));
						$query="UPDATE {cruq} SET cruq_list_data='" . $updated_data . "', last_update=NOW() WHERE uid=". $user->uid;
						$query_new = TRUE;
					}
					//list or contents are invalid
					else{
						echo "<br />Error: lname or checked is too short.<br />";
						echo "lname: ".$lname."<br />";
						echo "checked: ".json_encode($checked)."<br />";
					}
				}
				//missing one or more necessary arguments
				else{
					echo "<br />Error: missing one or more necessary arguments to create list $l_arg.";
				}
				
			}
		}
		//user has no CruQ data in database
		else{
			echo "<br />Error: User has no existing CruQ data.";
		}
	}
	
	//handle functions related to basic CruQ
	elseif($v_arg > 0){
		
		echo "uid is $user->uid , v is $v_arg";
		//form db query to check the node table (looking for a node of type "pairing" with nid==$_GET[v])
		$query = "SELECT nid FROM {node} WHERE type IN ('pairing','testpairing') AND nid='" . $v_arg . "'";
		$result = db_query($query);
		
		$valid = db_fetch_object($result);
		//$valid==FALSE if the value of $v_arg is not the nid of a pairing node
		if($valid){
			//database call to collect user's CruQ data
			$query = "SELECT cruq_list_data FROM {cruq} WHERE uid=". $user->uid;
			$result = db_query($query);
			
			$cur_cruq = db_fetch_object($result);
			//$cur_cruq==FALSE if the user's uid is not in the cruq database
			
			if($cur_cruq){
				//has existing data
				$cur_data = unserialize(base64_decode($cur_cruq->cruq_list_data));
				echo "<br />Current Data: " . json_encode($cur_data) . "<br />";
				
				if(strlen($cur_data[0][1])==0){
					$cur = array();
				}
				else{
					$cur = explode(",",$cur_data[0][1]);
				}
				
				if(!in_array($v_arg,$cur)){
					//append the new nid to the current list
					$cur[] = $v_arg;

					$cur_data[0][1] = implode(",",$cur);
					echo "<br />New Data: " . json_encode($cur_data). "<br />";

					$updated_data = base64_encode(serialize($cur_data));

					$query="UPDATE {cruq} SET cruq_list_data='" . $updated_data . "', last_update=NOW() WHERE uid=". $user->uid;
					$query_new = TRUE;
				}
			}
			else{
				//no existing data
				echo "<br />No existing Data.<br />";
				$to_update = array(array('My CruQ',implode(",",array($v_arg)),0));
				echo "<br />New Data: " . json_encode($to_update) . "<br />";
				
				$updated_data = base64_encode(serialize($to_update));
				
				$query="INSERT INTO {cruq} (uid,cruq_list_data,last_update) VALUES(" . $user->uid . ",'" . $updated_data."',NOW())";
				$query_new = TRUE;
			}
		}
	}
	//$l_arg or $v_arg invalid
	else{
		echo "Error: l/v are invalid.<br />";
		echo "l: ".$l_arg."<br />";
		echo "v: ".$v_arg."<br />";
	}
	
	//adjust data for the function/argument
	if($query_new){
		$result = db_query($query);
		if(!$result){
			echo "<br />Query failure<br />";
		}
	}
}
?>