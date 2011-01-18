<?php
/*****************************************************************************
 *
 * NagiosHost.php - Class of a Host in Nagios with all necessary information
 *
 * Copyright (c) 2004-2010 NagVis Project (Contact: info@nagvis.org)
 *
 * License:
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2 as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 *
 *****************************************************************************/
 
/**
 * @author	Lars Michelsen <lars@vertical-visions.de>
 */
class NagiosHost extends NagVisStatefulObject {
	protected $host_name;
	protected $alias;
	protected $display_name;
	protected $address;
	protected $statusmap_image;
	protected $notes;
	protected $check_command;
	
	protected $perfdata;
	protected $last_check;
	protected $next_check;
	protected $state_type;
	protected $current_check_attempt;
	protected $max_check_attempts;
	protected $last_state_change;
	protected $last_hard_state_change;
	
	protected $in_downtime;
	protected $downtime_start;
	protected $downtime_end;
	protected $downtime_author;
	protected $downtime_data;
	
	protected $fetchedChildObjects;
	protected $fetchedParentObjects;
	protected $childObjects;
	protected $parentObjects;
	protected $members;
	// An automap connector is a host which is not part of the automap selection
	// but needed as 'bridge' between two hosts which are part of the automap
	protected $automapConnector = false;

	protected static $langHostStateIs = null;
	protected static $langServices = null;
	
	/**
	 * Class constructor
	 *
	 * @param		Object 		Object of class GlobalMainCfg
	 * @param		Object 		Object of class CoreBackendMgmt
	 * @param		Object 		Object of class GlobalLanguage
	 * @param		Integer 		ID of queried backend
	 * @param		String		Name of the host
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	public function __construct($CORE, $BACKEND, $backend_id, $hostName) {
		$this->backend_id = $backend_id;
		
		$this->fetchedChildObjects = 0;
		$this->fetchedParentObjects = 0;
		$this->childObjects = Array();
		$this->parentObjects = Array();
		$this->members = Array();
		
		parent::__construct($CORE, $BACKEND);
		
		$this->host_name = $hostName;
	}
	
	/**
	 * PUBLIC fetchSummariesFromCounts()
	 *
	 * Fetches the summary state and output from the already set state counts
	 *
	 * @author  Lars Michelsen <lars@vertical-visions.de>
	 */
	public function fetchSummariesFromCounts() {
		// Generate summary output
		$this->fetchSummaryOutputFromCounts();
		
		// Add host state to counts
		// This should be done after output generation and before
		// summary state fetching. It could confuse the output fetching but
		// is needed for the summary state
		$this->addHostStateToStateCounts();
				
		// Calculate summary state
		$this->fetchSummaryStateFromCounts();
	}
	
	/**
	 * PUBLIC queueState()
	 *
	 * Queues the state fetching to the backend.
	 *
	 * @param   Boolean  Optional flag to disable fetching of the object status
	 * @param   Boolean  Optional flag to disable fetching of member status
	 * @author  Lars Michelsen <lars@vertical-visions.de>
	 */
	public function queueState($bFetchObjectState = true, $bFetchMemberState = true) {
		$queries = Array();
		
		if($bFetchObjectState)
			$queries['hostState']	= true;
		
		if($this->recognize_services)
			$queries['hostMemberState'] = true;
		
		if($this->hover_menu == 1
		   && $this->hover_childs_show == 1
		   && $bFetchMemberState
		   && !$this->hasMembers())
			$queries['hostMemberDetails'] = true;
		
		$this->BACKEND->queue($queries, $this);
	}
	
	/**
	 * PUBLIC applyState()
	 *
	 * Applies the fetched state
	 *
	 * @author  Lars Michelsen <lars@vertical-visions.de>
	 */
	public function applyState() {
		if($this->problem_msg) {
			$this->summary_state = 'ERROR';
			$this->summary_output = $this->problem_msg;
			$this->members = Array();
			return;
		}

		if($this->hasMembers()) {
			foreach($this->getMembers() AS $MOBJ) {
				$MOBJ->applyState();
			}
		}
		
		// Use state summaries when some are available to
		// calculate summary state and output
		if($this->aStateCounts !== null) {
			$this->fetchSummariesFromCounts();
		} else {
			$this->fetchSummaryState();
			$this->fetchSummaryOutput();
		}
	}
	
	/**
	 * PUBLIC fetchParents()
	 *
	 * Gets all parent objects of this host from the backend. The parent objects are
	 * saved to the parentObjects array.
	 *
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	public function fetchParents($maxLayers = -1, &$objConf = Array(), &$ignoreHosts = Array(), &$arrHostnames, &$arrMapObjects) {
		// Stop recursion when the number of layers counted down
		if($maxLayers != 0) {
			if(!$this->fetchedParentObjects) {
				$this->fetchDirectParentObjects($objConf, $ignoreHosts, $arrHostnames, $arrMapObjects);
			}
			
			foreach($this->parentObjects AS $OBJ) {
				$OBJ->fetchParents($maxLayers-1, $objConf, $ignoreHosts, $arrHostnames, $arrMapObjects);
			}
		}
	}
	/**
	 * PUBLIC filterParents()
	 *
	 * Filters the parents depending on the allowed hosts list. All objects which
	 * are not in the list and are no child of a host in this list will be
	 * removed from the map.
	 *
	 * @param	Array	List of allowed hosts
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	public function filterParents(&$arrAllowedHosts) {
		$remain = 0;
		
		$numChilds = $this->getNumParents();
		for($i = 0; $i < $numChilds; $i++) {
			$OBJ = &$this->parentObjects[$i];
			$selfRemain = 0;
			
			if(is_object($OBJ)) {
				/**
					* The current parent is member of the filter group, it declares 
					* itselfs as remaining object
					*/
				if(in_array($OBJ->getName(), $arrAllowedHosts)) {
					$selfRemain = 1;
				} else {
					$selfRemain = 0;
				}
				
				/**
					* If there are parent objects loop them all to get their remaining
					* state. If there is no parent object the only remaining state is
					* the state of the current parent object.
					*/
				if($OBJ->hasParents()) {
					$parentsRemain = $OBJ->filterParents($arrAllowedHosts);
					
					if(!$selfRemain && $parentsRemain) {
						$selfRemain = 1;
					}
				}
				
				// If the host should not remain on the map remove it from the 
				// object tree
				if(!$selfRemain) {
					// Remove the object from the tree
					unset($this->parentObjects[$i]);
				}
			}
			
			$remain |= $selfRemain;
		}
		return $remain;
	}
	
	/**
	 * PUBLIC fetchChilds()
	 *
	 * Gets all child objects of this host from the backend. The child objects are
	 * saved to the childObjects array
	 *
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	public function fetchChilds($maxLayers=-1, &$objConf=Array(), &$ignoreHosts=Array(), &$arrHostnames, &$arrMapObjects) {
		// Stop recursion when the number of layers counted down
		if($maxLayers != 0) {
			if(!$this->fetchedChildObjects) {
				$this->fetchDirectChildObjects($objConf, $ignoreHosts, $arrHostnames, $arrMapObjects);
			}
			
			/**
				* If maxLayers is not set there is no layer limitation
				*/
			if($maxLayers < 0 || $maxLayers > 0) {
				foreach($this->childObjects AS $OBJ) {
					$OBJ->fetchChilds($maxLayers-1, $objConf, $ignoreHosts, $arrHostnames, $arrMapObjects);
				}
			}
		}
	}
	
	/**
	 * PUBLIC filterChilds()
	 *
	 * Filters the children depending on the allowed hosts list. All objects which
	 * are not in the list and are no parent of a host in this list will be
	 * removed from the map.
	 *
	 * @param	Array	List of allowed hosts
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	public function filterChilds(&$arrAllowedHosts) {
		$remain = 0;
		
		$numChilds = $this->getNumChilds();
		for($i = 0; $i < $numChilds; $i++) {
			$OBJ = &$this->childObjects[$i];
			$selfRemain = 0;
			
			if(is_object($OBJ)) {
				/**
					* The current child is member of the filter group, it declares 
					* itselfs as remaining object
					*/
				if(in_array($OBJ->getName(), $arrAllowedHosts))
					$selfRemain = 1;
				
				/**
					* If there are child objects loop them all to get their remaining
					* state. If there is no child object the only remaining state is
					* the state of the current child object.
					*/
				if($OBJ->hasChilds()) {
					$childsRemain = $OBJ->filterChilds($arrAllowedHosts);
					
					if(!$selfRemain && $childsRemain) {
						$selfRemain = 1;
						$OBJ->automapConnector = true;
					}
				}
				
				// If the host should not remain on the map remove it from the 
				// object tree
				if(!$selfRemain)
					unset($this->childObjects[$i]);
			}
			
			$remain |= $selfRemain;
		}
		return $remain;
	}
	
	/**
	 * PUBLIC getChildsAndParents()
	 *
	 * Returns all childs and parent objects
	 *
	 * @return	Array		Array of host objects
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	public function getChildsAndParents() {
		return array_merge($this->parentObjects, $this->childObjects);
	}
	
	/**
	 * PUBLIC getNumParents()
	 *
	 * Returns the count of parent objects
	 *
	 * @return	Integer		Number of child objects
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	public function getNumParents() {
		return count($this->parentObjects);
	}
	
	/**
	 * PUBLIC getParents()
	 *
	 * Returns all parent objects in parentObjects array 
	 *
	 * @return	Array		Array of host objects
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	public function getParents() {
		return $this->parentObjects;
	}
	
	/**
	 * PUBLIC hasParents()
	 *
	 * Simple check if the host has at least one parent
	 *
	 * @return Boolean	Yes: Has parents, No: No parent
	 * @author  Lars Michelsen <lars@vertical-visions.de>
	 */
	public function hasParents() {
		return isset($this->parentObjects[0]);
	}
	
	/**
	 * PUBLIC getNumChilds()
	 *
	 * Returns the count of child objects
	 *
	 * @return	Integer		Number of child objects
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	public function getNumChilds() {
		return count($this->childObjects);
	}
	
	/**
	 * PUBLIC getChilds()
	 *
	 * Returns all child objects in childObjects array 
	 *
	 * @return	Array		Array of host objects
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	public function getChilds() {
		return $this->childObjects;
	}
	
	/**
	 * PUBLIC hasChilds()
	 *
	 * Simple check if the host has at least one child
	 *
	 * @return Boolean	Yes: Has children, No: No Child
	 * @author  Lars Michelsen <lars@vertical-visions.de>
	 */
	public function hasChilds() {
		return isset($this->childObjects[0]);
	}
	
	/**
	 * PUBLIC getNumMembers()
	 *
	 * Returns the number of services
	 *
	 * @return	Integer		Number of services
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	public function getNumMembers() {
		return count($this->members);
	}
	
	/**
	 * PUBLIC getMembers()
	 *
	 * Returns the number of services
	 *
	 * @return	Array		Array of Services
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	public function getMembers() {
		return $this->members;
	}
	
	/**
	 * PUBLIC hasMembers()
	 *
	 * Simple check if the host has at least one service
	 *
	 * @return Boolean	Yes: Has services, No: No Service
	 * @author  Lars Michelsen <lars@vertical-visions.de>
	 */
	public function hasMembers() {
		return isset($this->members[0]);
	}
	
	# End public methods
	# #########################################################################
	
	/**
	 * PRIVATE fetchDirectParentObjects()
	 *
	 * Gets all parent objects of the given host and saves them to the parentObjects
	 * array
	 *
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	private function fetchDirectParentObjects(&$objConf, &$ignoreHosts=Array(), &$arrHostnames, &$arrMapObjects) {
		try {
			$aParents = $this->BACKEND->getBackend($this->backend_id)->getDirectParentNamesByHostName($this->getName());
		} catch(BackendException $e) {
			$aParents = Array();
		}
		foreach($aParents AS $parentName) {
			// If the host is in ignoreHosts, don't recognize it
			if(count($ignoreHosts) == 0 || !in_array($childName, $ignoreHosts)) {
				/*
				 * Check if the host is already on the map (If it's not done, the 
				 * objects with more than one parent will be printed several times on the 
				 * map, especially the links to child objects will be too many.
				 */
				if(!in_array($parentName, $arrHostnames)){
					$OBJ = new NagVisHost($this->CORE, $this->BACKEND, $this->backend_id, $parentName);
					$OBJ->setConfiguration($objConf);
					$OBJ->fetchIcon();
					
					// Append the object to the parentObjects array
					$this->parentObjects[] = $OBJ;
					
					// Append the object to the arrMapObjects array
					$arrMapObjects[] = $this->parentObjects[count($this->parentObjects)-1];
					
					// Add the name of this host to the array with hostnames which are
					// already on the map
					$arrHostnames[] = $OBJ->getName();
				} else {
					// Add reference of already existing host object to the
					// child objects array
					foreach($arrMapObjects AS $OBJ) {
						if($OBJ->getName() == $parentName) {
							$this->childObjects[] = $OBJ;
						}
					}
				}
			}
		}

		// All parents were fetched, save the state for this object
		$this->fetchedParentObjects = 1;
	}
	
	/**
	 * PRIVATE fetchDirectChildObjects()
	 *
	 * Gets all child objects of the given host and saves them to the childObjects
	 * array
	 *
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	private function fetchDirectChildObjects(&$objConf, &$ignoreHosts=Array(), &$arrHostnames, &$arrMapObjects) {
		try {
			$aChilds = $this->BACKEND->getBackend($this->backend_id)->getDirectChildNamesByHostName($this->getName());
		} catch(BackendException $e) {
			$aChilds = Array();
		}
		foreach($aChilds AS $childName) {
			// If the host is in ignoreHosts, don't recognize it
			if(count($ignoreHosts) == 0 || !in_array($childName, $ignoreHosts)) {
				/*
				 * Check if the host is already on the map (If it's not done, the 
				 * objects with more than one parent will be printed several times on the 
				 * map, especially the links to child objects will be too many.
				 */
				if(!in_array($childName, $arrHostnames)){
					$OBJ = new NagVisHost($this->CORE, $this->BACKEND, $this->backend_id, $childName);
					$OBJ->setConfiguration($objConf);
					$OBJ->fetchIcon();
					
					// Append the object to the childObjects array
					$this->childObjects[] = $OBJ;
					
					// Append the object to the arrMapObjects array
					$arrMapObjects[] = $this->childObjects[count($this->childObjects)-1];
					
					// Add the name of this host to the array with hostnames which are
					// already on the map
					$arrHostnames[] = $OBJ->getName();
				} else {
					// Add reference of already existing host object to the
					// child objects array
					foreach($arrMapObjects AS $OBJ) {
						if($OBJ->getName() == $childName) {
							$this->childObjects[] = $OBJ;
						}
					}
				}
			}
		}

		// All children were fetched, save the state for this object
		$this->fetchedChildObjects = 1;
	}
	
	/**
	 * PRIVATE fetchSummaryState()
	 *
	 * Fetches the summary state from all services
	 *
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	private function fetchSummaryState() {
		// Get Host state
		$this->summary_state = $this->state;
		$this->summary_problem_has_been_acknowledged = $this->problem_has_been_acknowledged;
		$this->summary_in_downtime = $this->in_downtime;
		
		// Only merge host state with service state when recognize_services is set to 1
		if($this->recognize_services)
			$this->wrapChildState($this->getMembers());
	}
		
	/**
	 * PUBLIC addHostStateToStateCounts()
	 *
	 * Adds the current host state to the member state counts
	 *
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	private function addHostStateToStateCounts() {
		$sState = $this->state;

		if(NagVisObject::$stateWeight === null)
			NagVisObject::$stateWeight = $this->CORE->getMainCfg()->getStateWeight();
		
		$sType = 'normal';
		if($this->problem_has_been_acknowledged == 1 && isset(NagVisObject::$stateWeight[$sState]['ack'])) {
			$sType = 'ack';
		} elseif($this->in_downtime == 1 && isset(NagVisObject::$stateWeight[$sState]['downtime'])) {
			$sType = 'downtime';
		}
		
		if(!isset($this->aStateCounts[$sState]))
			$this->aStateCounts[$sState] = Array($sType => 1);
		elseif(!isset($this->aStateCounts[$sState][$sType]))
			$this->aStateCounts[$sState][$sType] = 1;
		else
			$this->aStateCounts[$sState][$sType] += 1;
	}

	/**
	 * PRIVATE fetchSummaryOutputFromCounts()
	 *
	 * Fetches the summary output from the object state counts
	 *
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	private function fetchSummaryOutputFromCounts() {
		if(NagiosHost::$langHostStateIs === null)
			NagiosHost::$langHostStateIs = $this->CORE->getLang()->getText('hostStateIs');

		// Write host state
		$this->summary_output = NagiosHost::$langHostStateIs.' '.$this->state.'. ';
		
		// Only merge host state with service state when recognize_services is set 
		// to 1
		if($this->recognize_services) {
			$iNumServices = 0;
			$arrServiceStates = Array();
			
			// Loop all major states
			if($this->aStateCounts !== null) {
				foreach($this->aStateCounts AS $sState => $aSubstates) {
					// Ignore host state here
					if($sState != 'UP' && $sState != 'DOWN' && $sState != 'UNREACHABLE') {
						// Loop all substates (normal,ack,downtime,...)
						foreach($aSubstates AS $sSubState => $iCount) {
							// Found some objects with this state+substate
							if($iCount > 0) {
								if(!isset($arrServiceStates[$sState])) {
									$arrServiceStates[$sState] = $iCount;
									$iNumServices += $iCount;
								} else {
									$arrServiceStates[$sState] += $iCount;
									$iNumServices += $iCount;
								}
							}
						}
					}
				}
			}
			
			if($iNumServices > 0) {
				if(NagiosHost::$langServices === null)
					NagiosHost::$langServices = $this->CORE->getLang()->getText('services');
				
				$this->mergeSummaryOutput($arrServiceStates, NagiosHost::$langServices);
			} else {
				$this->summary_output .= $this->CORE->getLang()->getText('hostHasNoServices','HOST~'.$this->getName());
			}
		}
	}
	
	/**
	 * PRIVATE fetchSummaryOutput()
	 *
	 * Fetches the summary output from host and all services
	 *
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	private function fetchSummaryOutput() {
		// Write host state
		$this->summary_output = $this->CORE->getLang()->getText('hostStateIs').' '.$this->state.'. ';
		
		// Only merge host state with service state when recognize_services is set 
		// to 1
		if($this->recognize_services) {
			// If there are services write the summary state for them
			if($this->hasMembers()) {
				$arrStates = Array('CRITICAL' => 0,'DOWN' => 0,'WARNING' => 0,'UNKNOWN' => 0,'UP' => 0,'OK' => 0,'ERROR' => 0,'ACK' => 0,'PENDING' => 0);
				
				foreach($this->members AS &$SERVICE) {
					$arrStates[$SERVICE->getSummaryState()]++;
				}
				
				$this->mergeSummaryOutput($arrStates, $this->CORE->getLang()->getText('services'));
			} else {
				$this->summary_output .= $this->CORE->getLang()->getText('hostHasNoServices','HOST~'.$this->getName());
			}
		}
	}
}
?>
