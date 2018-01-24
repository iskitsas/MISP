<?php
App::uses('AppController', 'Controller');
App::uses('Xml', 'Utility');

class EventsController extends AppController {

	public $components = array(
			'Security',
			'Email',
			'RequestHandler',
			'IOCImport',
			'Cidr'
	);

	public $paginate = array(
			'limit' => 60,
			'maxLimit' => 9999,	// LATER we will bump here on a problem once we have more than 9999 events <- no we won't, this is the max a user van view/page.
			'order' => array(
					'Event.timestamp' => 'DESC'
			),
			'contain' => array(
					'Org' => array('fields' => array('id', 'name')),
					'Orgc' => array('fields' => array('id', 'name')),
					'SharingGroup' => array('fields' => array('id', 'name'))
			)
	);

	public $helpers = array('Js' => array('Jquery'));

	public $paginationFunctions = array('index', 'proposalEventIndex');

	public function beforeFilter() {
		parent::beforeFilter();

		// what pages are allowed for non-logged-in users
		$this->Auth->allow('xml');
		$this->Auth->allow('csv');
		$this->Auth->allow('nids');
		$this->Auth->allow('hids_md5');
		$this->Auth->allow('hids_sha1');
		$this->Auth->allow('text');
		$this->Auth->allow('restSearch');
		$this->Auth->allow('stix');
		$this->Auth->allow('stix2');

		// TODO Audit, activate logable in a Controller
		if (count($this->uses) && $this->{$this->modelClass}->Behaviors->attached('SysLogLogable')) {
			$this->{$this->modelClass}->setUserData($this->activeUser);
		}

		// convert uuid to id if present in the url, and overwrite id field
		if (isset($this->params->query['uuid'])) {
			$params = array(
					'conditions' => array('Event.uuid' => $this->params->query['uuid']),
					'recursive' => 0,
					'fields' => 'Event.id'
			);
			$result = $this->Event->find('first', $params);
			if (isset($result['Event']) && isset($result['Event']['id'])) {
				$id = $result['Event']['id'];
				$this->params->addParams(array('pass' => array($id))); // FIXME find better way to change id variable if uuid is found. params->url and params->here is not modified accordingly now
			}
		}

		// if not admin or own org, check private as well..
		if (!$this->_isSiteAdmin() && in_array($this->action, $this->paginationFunctions)) {
			$sgids = $this->Event->cacheSgids($this->Auth->user(), true);
			$conditions = array(
				'AND' => array(
					array(
						"OR" => array(
							array(
								'Event.org_id' => $this->Auth->user('org_id')
							),
							array(
								'AND' => array(
										'Event.distribution >' => 0,
										'Event.distribution <' => 4,
										Configure::read('MISP.unpublishedprivate') ? array('Event.published =' => 1) : array(),
								),
							),
							array(
								'AND' => array(
										'Event.distribution' => 4,
										'Event.sharing_group_id' => $sgids,
										Configure::read('MISP.unpublishedprivate') ? array('Event.published =' => 1) : array(),
								),
							)
						)
					)
				)
			);
			if ($this->userRole['perm_sync'] && $this->Auth->user('Server')['push_rules']) {
				$conditions['AND'][] = $this->Event->filterRulesToConditions($this->Auth->user('Server')['push_rules']);
			}
			$this->paginate = Set::merge($this->paginate,array('conditions' => $conditions));
		}
	}

	private function __filterOnAttributeValue($value) {
		// dissect the value
		$pieces = explode('|', $value);
		$include = array();
		$exclude = array();
		$includeIDs = array();
		$excludeIDs = array();
		foreach ($pieces as $piece) {
			if ($piece[0] == '!') {
				$exclude[] =  '%' . strtolower(substr($piece, 1)) . '%';
			} else {
				$include[] = '%' . strtolower($piece) . '%';
			}
		}
		if (!empty($include)) {
			// get all of the attributes that should be included
			$includeQuery = array(
					'recursive' => -1,
					'fields' => array('id', 'event_id', 'distribution', 'value1', 'value2'),
					'conditions' => array(),
			);
			foreach ($include as $i) {
				$includeQuery['conditions']['OR'][] = array('lower(Attribute.value1) LIKE' => $i);
				$includeQuery['conditions']['OR'][] = array('lower(Attribute.value2) LIKE' => $i);
			}
			$includeQuery['conditions']['AND'][] = array('Attribute.deleted' => 0);
			$includeHits = $this->Event->Attribute->find('all', $includeQuery);

			// convert it into an array that uses the event ID as a key
			foreach ($includeHits as $iH) {
				$includeIDs[$iH['Attribute']['event_id']][] = array('attribute_id' => $iH['Attribute']['id'], 'distribution' => $iH['Attribute']['distribution']);
			}
		}

		if (!empty($exclude)) {
			// get all of the attributes that should be excluded
			$excludeQuery = array(
				'recursive' => -1,
				'fields' => array('id', 'event_id', 'distribution', 'value1', 'value2'),
				'conditions' => array(),
			);
			foreach ($exclude as $e) {
				$excludeQuery['conditions']['OR'][] = array('lower(Attribute.value1) LIKE' => $e);
				$excludeQuery['conditions']['OR'][] = array('lower(Attribute.value2) LIKE' => $e);
			}
			$excludeQuery['conditions']['AND'][] = array('Attribute.deleted' => 0);
			$excludeHits = $this->Event->Attribute->find('all', $excludeQuery);

			// convert it into an array that uses the event ID as a key
			foreach ($excludeHits as $eH) {
				$excludeIDs[$eH['Attribute']['event_id']][] = array('attribute_id' => $eH['Attribute']['id'], 'distribution' => $eH['Attribute']['distribution']);
			}
		}
		$includeIDs = array_keys($includeIDs);
		$excludeIDs = array_keys($excludeIDs);
		// return -1 as the only value in includedIDs if both arrays are empty. This will mean that no events will be shown if there was no hit
		if (empty($includeIDs) && empty($excludeIDs)) {
			$includeIDs[] = -1;
		}
		return array($includeIDs, $excludeIDs);
	}

	private function __quickFilter($value) {
		if (!is_array($value)) $value = array($value);
		$values = array();
		foreach ($value as $v) {
			$values[] = '%' . strtolower($v) . '%';
		}

		$result = array();
		// get all of the attributes that have a hit on the search term, in either the value or the comment field
		// This is not perfect, the search will be case insensitive, but value1 and value2 are searched separately. lower() doesn't seem to work on virtualfields
		$subconditions = array();
		foreach ($values as $v) {
			$subconditions[] = array('lower(value1) LIKE' => $v);
			$subconditions[] = array('lower(value2) LIKE' => $v);
			$subconditions[] = array('lower(Attribute.comment) LIKE' => $v);
		}
		$conditions = array(
			'AND' => array(
				'OR' => $subconditions,
				'Attribute.deleted' => 0
			)
		);
		$attributeHits = $this->Event->Attribute->fetchAttributes($this->Auth->user(), array(
				'conditions' => $conditions,
				'fields' => array('event_id', 'comment', 'distribution', 'value1', 'value2')
		));
		// rearrange the data into an array where the keys are the event IDs
		$eventsWithAttributeHits = array();
		foreach ($attributeHits as $aH) {
			$eventsWithAttributeHits[$aH['Attribute']['event_id']][] = $aH['Attribute'];
		}

		// Using the keys from the previously obtained ordered array, let's fetch all of the events involved
		$events = $this->Event->find('all', array(
				'recursive' => -1,
				'fields' => array('id', 'distribution', 'org_id'),
				'conditions' => array('id' => array_keys($eventsWithAttributeHits)),
		));

		foreach ($events as $event) {
			$result[] = $event['Event']['id'];
		}

		// we now have a list of event IDs that match on an attribute level, and the user can see it. Let's also find all of the events that match on other criteria!
		// What is interesting here is that we no longer have to worry about the event's releasability. With attributes this was a different case,
		// because we might run into a situation where a user can see an event but not a specific attribute
		// returning a hit on such an attribute would allow users to enumerate hidden attributes
		// For anything beyond this point the default pagination restrictions will apply!

		// First of all, there are tags that might be interesting for us
		$subconditions = array();
		foreach ($values as $v) {
			$subconditions[] = array('lower(name) LIKE' => $v);
		}
		$tags = $this->Event->EventTag->Tag->find('all', array(
				'conditions' => $subconditions,
				'fields' => array('name', 'id'),
				'contain' => array('EventTag', 'AttributeTag'),
		));
		foreach ($tags as $tag) {
			foreach ($tag['EventTag'] as $eventTag) {
				if (!in_array($eventTag['event_id'], $result)) $result[] = $eventTag['event_id'];
			}
			foreach ($tag['AttributeTag'] as $attributeTag) {
				if (!in_array($attributeTag['event_id'], $result)) $result[] = $attributeTag['event_id'];
			}
		}

		// Finally, let's search on the event metadata!
		$subconditions = array();
		foreach ($values as $v) {
			$subconditions[] = array('lower(name) LIKE' => $v);
		}
		$conditions = array();
		$orgs = $this->Event->Org->find('list', array(
				'conditions' => $subconditions,
				'recursive' => -1,
				'fields' => array('id')
		));
		foreach ($values as $v) {
			$conditions['OR'][] = array('lower(info) LIKE' => $v);
			$conditions['OR'][] = array('lower(uuid) LIKE' => $v);
		}
		if (!empty($orgs)) $conditions['OR']['orgc_id'] = array_values($orgs);
		$otherEvents = $this->Event->find('all', array(
				'recursive' => -1,
				'fields' => array('id', 'orgc_id', 'info', 'uuid'),
				'conditions' => $conditions,
		));
		foreach ($otherEvents as $oE) {
			if (!in_array($oE['Event']['id'], $result)) $result[] = $oE['Event']['id'];
		}
		return $result;
	}

	public function index() {
		// list the events
		$passedArgsArray = array();
		$urlparams = "";
		$overrideAbleParams = array('all', 'attribute', 'published', 'eventid', 'Datefrom', 'Dateuntil', 'org', 'eventinfo', 'tag', 'distribution', 'analysis', 'threatlevel', 'email', 'hasproposal', 'timestamp', 'publishtimestamp', 'publish_timestamp', 'minimal');
		$passedArgs = $this->passedArgs;
		if (isset($this->request->data)) {
			if (isset($this->request->data['request'])) $this->request->data = $this->request->data['request'];
			foreach ($overrideAbleParams as $oap) {
				if (isset($this->request->data['search' . $oap])) $this->request->data[$oap] = $this->request->data['search' . $oap];
				if (isset($this->request->data[$oap])) $passedArgs['search' . $oap] = $this->request->data[$oap];
			}
		}
		$this->set('passedArgs', json_encode($passedArgs));
		// check each of the passed arguments whether they're a filter (could also be a sort for example) and if yes, add it to the pagination conditions
		foreach ($passedArgs as $k => $v) {
			if (substr($k, 0, 6) === 'search') {
				if (!is_array($v)) {
					if ($urlparams != "") $urlparams .= "/";
					$urlparams .= $k . ":" . $v;
				}
				$searchTerm = strtolower(substr($k, 6));
				switch ($searchTerm) {
					case 'all' :
						if (!empty($passedArgs['searchall'])) $this->paginate['conditions']['AND'][] = array('Event.id' => $this->__quickFilter($passedArgs['searchall']));
						break;
					case 'attribute' :
						$event_id_arrays = $this->__filterOnAttributeValue($v);
						foreach ($event_id_arrays[0] as $event_id) $this->paginate['conditions']['AND']['OR'][] = array('Event.id' => $event_id);
						foreach ($event_id_arrays[1] as $event_id) $this->paginate['conditions']['AND'][] = array('Event.id !=' => $event_id);
						break;
					case 'published' :
						if ($v == 2) continue 2;
						$this->paginate['conditions']['AND'][] = array('Event.' . substr($k, 6) . ' =' => $v);
						break;
					case 'hasproposal' :
						if ($v == 2) continue 2;
						$proposalQuery = "exists (select id, deleted from shadow_attributes where shadow_attributes.event_id = Event.id and shadow_attributes.deleted = 0)";
						if ($v == 0) $proposalQuery = 'not ' . $proposalQuery;
						$this->paginate['conditions']['AND'][] = $proposalQuery;
						break;
					case 'eventid':
						if ($v == "") continue 2;
						$pieces = explode('|', $v);
						$temp = array();
						foreach ($pieces as $piece) {
							$piece = trim($piece);
							if ($piece[0] == '!') {
								if (strlen($piece) == 37) {
									$this->paginate['conditions']['AND'][] = array('Event.uuid !=' => substr($piece, 1));
								} else {
									$this->paginate['conditions']['AND'][] = array('Event.id !=' => substr($piece, 1));
								}
							} else {
								if (strlen($piece) == 36) {
									$temp['OR'][] = array('Event.uuid' => $piece);
								} else {
									$temp['OR'][] = array('Event.id' => $piece);
								}
							}
						}
						$this->paginate['conditions']['AND'][] = $temp;
						break;
					case 'datefrom':
						if ($v == "") continue 2;
						$this->paginate['conditions']['AND'][] = array('Event.date >=' => $v);
						break;
					case 'dateuntil':
						if ($v == "") continue 2;
						$this->paginate['conditions']['AND'][] = array('Event.date <=' => $v);
						break;
					case 'timestamp':
						if ($v == "") continue 2;
						$this->paginate['conditions']['AND'][] = array('Event.timestamp >=' => $v);
						break;
					case 'publish_timestamp':
					case 'publishtimestamp':
						if ($v == "") continue 2;
						if (is_array($v) && isset($v[0]) && isset($v[1])) {
							$this->paginate['conditions']['AND'][] = array('Event.publish_timestamp >=' => $v[0]);
							$this->paginate['conditions']['AND'][] = array('Event.publish_timestamp <=' => $v[1]);
						} else {
							$this->paginate['conditions']['AND'][] = array('Event.publish_timestamp >=' => $v);
						}
						break;
					case 'org' :
						if ($v == "") continue 2;
						if (!Configure::read('MISP.showorg')) continue 2;
						$orgArray = $this->Event->Org->find('list', array('fields' => array('Org.name')));
						$orgArray = array_map('strtoupper', $orgArray);
						// if the first character is '!', search for NOT LIKE the rest of the string (excluding the '!' itself of course)
						$pieces = explode('|', $v);
						$test = array();
						foreach ($pieces as $piece) {
							if ($piece[0] == '!') {
								if (is_numeric(substr($piece, 1))) {
									$this->paginate['conditions']['AND'][] = array('Event.orgc_id !=' => substr($piece, 1));
								} else {
									$org_id = array_search(strtoupper(substr($piece, 1)), $orgArray);
									if ($org_id) $this->paginate['conditions']['AND'][] = array('Event.orgc_id !=' => $org_id);
								}
							} else {
								if (is_numeric($piece)) {
									$test['OR'][] = array('Event.orgc_id' => array('Event.orgc_id' => $piece));
								} else {
									$org_id = array_search(strtoupper($piece), $orgArray);
									if ($org_id) $test['OR'][] = array('Event.orgc_id' => $org_id);
									else $test['OR'][] = array('Event.orgc_id' => -1);
								}
							}
						}
						$this->paginate['conditions']['AND'][] = $test;
						break;
					case 'eventinfo' :
						if ($v == "") continue 2;
						// if the first character is '!', search for NOT LIKE the rest of the string (excluding the '!' itself of course)
						$pieces = explode('|', $v);
						$test = array();
						foreach ($pieces as $piece) {
							if ($piece[0] == '!') {
								$this->paginate['conditions']['AND'][] = array('lower(Event.info)' . ' NOT LIKE' => '%' . strtolower(substr($piece, 1)) . '%');
							} else {
								$test['OR'][] = array('lower(Event.info)' . ' LIKE' => '%' . strtolower($piece) . '%');
							}
						}
						$this->paginate['conditions']['AND'][] = $test;
						break;
					case 'tag' :
						if (!$v || !Configure::read('MISP.tagging') || $v === 0) continue 2;
						$pieces = explode('|', $v);
						$filterString = "";
						$expectOR = false;
						$setOR = false;
						foreach ($pieces as $piece) {
							if ($piece[0] == '!') {
								if (is_numeric(substr($piece, 1))) {
									$conditions = array('OR' => array('Tag.id' => substr($piece, 1)));
								} else {
									$conditions = array('OR' => array('Tag.name' => substr($piece, 1)));
								}
								$tagName = $this->Event->EventTag->Tag->find('first', array(
									'conditions' => $conditions,
									'fields' => array('id', 'name'),
									'recursive' => -1,
								));

								if (empty($tagName)) {
									if ($filterString != "") $filterString .= "|";
									$filterString .= '!' . $piece;
									continue;
								}
								$block = $this->Event->EventTag->find('all', array(
										'conditions' => array('EventTag.tag_id' => $tagName['Tag']['id']),
										'fields' => 'event_id',
										'recursive' => -1,
								));
								if (!empty($block)) {
									$sqlSubQuery = 'Event.id NOT IN (';
									foreach ($block as $b) {
										$sqlSubQuery .= $b['EventTag']['event_id'] . ',';
									}
									$this->paginate['conditions']['AND'][] = substr($sqlSubQuery, 0, -1) . ')';
								}
								if ($filterString != "") $filterString .= "|";
								$filterString .= '!' . (isset($tagName['Tag']['name']) ? $tagName['Tag']['name'] : $piece);
							} else {
								$expectOR = true;
								if (is_numeric($piece)) {
									$conditions = array('OR' => array('Tag.id' => $piece));
								} else {
									$conditions = array('OR' => array('Tag.name' => $piece));
								}

								$tagName = $this->Event->EventTag->Tag->find('first', array(
										'conditions' => $conditions,
										'fields' => array('id', 'name'),
										'recursive' => -1,
								));

								if (empty($tagName)) {
									if ($filterString != "") $filterString .= "|";
									$filterString .= $piece;
									continue;
								}

								$allow = $this->Event->EventTag->find('all', array(
										'conditions' => array('EventTag.tag_id' => $tagName['Tag']['id']),
										'fields' => 'event_id',
										'recursive' => -1,
								));
								if (!empty($allow)) {
									$sqlSubQuery = 'Event.id IN (';
									foreach ($allow as $a) {
										$setOR = true;
										$sqlSubQuery .= $a['EventTag']['event_id'] . ',';
									}
									$this->paginate['conditions']['AND']['OR'][] = substr($sqlSubQuery, 0, -1) . ')';
								}
								if ($filterString != "") $filterString .= "|";
								$filterString .= isset($tagName['Tag']['name']) ? $tagName['Tag']['name'] : $piece;
							}
						}
						// If we have a list of OR-d arguments, we expect to end up with a list of allowed event IDs
						// If we don't however, it means that none of the tags was found. To prevent displaying the entire event index in this case:
						if ($expectOR && !$setOR) $this->paginate['conditions']['AND'][] = array('Event.id' => -1);
						$v = $filterString;
						break;
					case 'email':
						if ($v == "" || (strtolower($this->Auth->user('email')) !== strtolower(trim($v)) && !$this->_isSiteAdmin())) continue 2;
						// if the first character is '!', search for NOT LIKE the rest of the string (excluding the '!' itself of course)
						$pieces = explode('|', $v);
						$test = array();
						foreach ($pieces as $piece) {
							if ($piece[0] == '!') {
								$users = $this->Event->User->find('list', array(
										'recursive' => -1,
										'fields' => array('User.email'),
										'conditions' => array('lower(User.email) LIKE' => '%' . strtolower(substr($piece, 1)) . '%')
								));
								if (!empty($users)) $this->paginate['conditions']['AND'][] = array('Event.user_id !=' => array_keys($users));
							} else {
								$users = $this->Event->User->find('list', array(
										'recursive' => -1,
										'fields' => array('User.email'),
										'conditions' => array('lower(User.email) LIKE' => '%' . strtolower($piece) . '%')
								));
								if (!empty($users)) $test['OR'][] = array('Event.user_id' => array_keys($users));
							}
						}

						if (!empty($test)) $this->paginate['conditions']['AND'][] = $test;
						break;
					case 'distribution' :
					case 'analysis' :
					case 'threatlevel' :
						if ($v == "") continue 2;
						$terms = array();
						$filterString = "";
						$searchTermInternal = $searchTerm;
						if ($searchTerm == 'threatlevel') {
							$searchTermInternal = 'threat_level_id';
							$threatLevels = $this->Event->ThreatLevel->find('all', array(
								'recursive' => -1,
								'fields' => array('id', 'name'),
							));
							foreach ($threatLevels as $tl) {
								$terms[$tl['ThreatLevel']['id']] = $tl['ThreatLevel']['name'];
							}
						} else if ($searchTerm == 'analysis') {
							$terms = $this->Event->analysisLevels;
						} else {
							$terms = $this->Event->distributionLevels;
						}
						$pieces = explode('|', $v);
						$test = array();
						foreach ($pieces as $piece) {
							if ($filterString != "") $filterString .= '|';
							if ($piece[0] == '!') {
								$filterString .= $terms[substr($piece, 1)];
								$this->paginate['conditions']['AND'][] = array('Event.' . $searchTermInternal . ' !=' => substr($piece, 1));
							} else {
								$filterString .= $terms[$piece];
								$test['OR'][] = array('Event.' . $searchTermInternal => $piece);
							}
						}
						$this->paginate['conditions']['AND'][] = $test;
						$v = $filterString;
						break;
					default:
						continue 2;
						break;
				}
				$passedArgsArray[$searchTerm] = $v;
			}
		}
		if (Configure::read('MISP.tagging') && !$this->_isRest()) {
			$this->paginate['contain'] = array_merge($this->paginate['contain'], array('User.email', 'EventTag'));
		} else {
			$this->paginate['contain'] = array_merge($this->paginate['contain'], array('User.email'));
		}
		$this->set('urlparams', $urlparams);
		$this->set('passedArgsArray', $passedArgsArray);
		$this->paginate = Set::merge($this->paginate, array('contain' => array(
			'ThreatLevel' => array(
				'fields' => array(
					'ThreatLevel.name'))
			),
		));
		$this->loadModel('GalaxyCluster');
		// for REST, don't use the pagination. With this, we'll escape the limit of events shown on the index.
		if ($this->_isRest()) {
			$rules = array();
			$fieldNames = array_keys($this->Event->getColumnTypes());
			$directions = array('ASC', 'DESC');
			if (isset($passedArgs['sort']) && in_array($passedArgs['sort'], $fieldNames)) {
				if (isset($passedArgs['direction']) && in_array(strtoupper($passedArgs['direction']), $directions)) {
					$rules['order'] = array('Event.' . $passedArgs['sort'] => $passedArgs['direction']);
				} else {
					$rules['order'] = array('Event.' . $passedArgs['sort'] => 'ASC');
				}
			} else {
				$rules['order'] = array('Event.id' => 'DESC');
			}
			if (isset($passedArgs['limit'])) {
				$rules['limit'] = intval($passedArgs['limit']);
			}
			if (isset($passedArgs['page'])) {
				$rules['page'] = intval($passedArgs['page']);
			}
			$rules['contain'] = $this->paginate['contain'];
			if (Configure::read('MISP.tagging') && empty($passedArgs['searchminimal'])) {
				$rules['contain']['EventTag'] = array('Tag' => array('fields' => array('id', 'name', 'colour', 'exportable'), 'conditions' => array('Tag.exportable' => true)));
			}
			if (isset($this->paginate['conditions'])) $rules['conditions'] = $this->paginate['conditions'];
			if (!empty($passedArgs['searchminimal'])) {
				unset($rules['contain']);
				$rules['recursive'] = -1;
				$rules['fields'] = array('id', 'timestamp', 'published', 'uuid');
			}
			$events = $this->Event->find('all', $rules);
			if (empty($passedArgs['searchminimal'])) {
				foreach ($events as $k => $event) {
					foreach ($event['EventTag'] as $k2 => $et) {
						if (empty($et['Tag'])) unset($events[$k]['EventTag'][$k2]);
					}
					$events[$k]['EventTag'] = array_values($events[$k]['EventTag']);
				}
				$events = $this->GalaxyCluster->attachClustersToEventIndex($events);
				$this->set('events', $events);
			} else {
				foreach ($events as $key => $event) {
					$events[$key] = $event['Event'];
				}
				return $this->RestResponse->viewData($events, $this->response->type());
			}
		} else {
			$events = $this->paginate();
			if (count($events) == 1 && isset($this->passedArgs['searchall'])) {
				$this->redirect(array('controller' => 'events', 'action' => 'view', $events[0]['Event']['id']));
			}
			$events = $this->Event->attachTagsToEvents($events);
			if (Configure::read('MISP.showCorrelationsOnIndex')) $events = $this->Event->attachCorrelationCountToEvents($this->Auth->user(), $events);
			if (Configure::read('MISP.showSightingsCountOnIndex') && Configure::read('MISP.Plugin.Sightings_enable') !== false) $events = $this->Event->attachSightingsCountToEvents($this->Auth->user(), $events);
			if (Configure::read('MISP.showProposalsCountOnIndex')) $events = $this->Event->attachProposalsCountToEvents($this->Auth->user(), $events);
			if (Configure::read('MISP.showDiscussionsCountOnIndex')) $events = $this->Event->attachDiscussionsCountToEvents($this->Auth->user(), $events);
			$events = $this->GalaxyCluster->attachClustersToEventIndex($events, true);
			$this->set('events', $events);
		}

		if (!$this->Event->User->getPGP($this->Auth->user('id')) && Configure::read('GnuPG.onlyencrypted')) {
			// No GPG
			if (Configure::read('SMIME.enabled') && !$this->Event->User->getCertificate($this->Auth->user('id'))) {
				// No GPG and No SMIME
				$this->Session->setFlash(__('No x509 certificate or GPG key set in your profile. To receive emails, submit your public certificate or GPG key in your profile.'));
			} else if (!Configure::read('SMIME.enabled')) {
				$this->Session->setFlash(__('No GPG key set in your profile. To receive emails, submit your public key in your profile.'));
			}
		} else if ($this->Auth->user('autoalert') && !$this->Event->User->getPGP($this->Auth->user('id')) && Configure::read('GnuPG.bodyonlyencrypted')) {
			// No GPG & autoalert
			if ($this->Auth->user('autoalert') && Configure::read('SMIME.enabled') && !$this->Event->User->getCertificate($this->Auth->user('id'))) {
				// No GPG and No SMIME & autoalert
				$this->Session->setFlash(__('No x509 certificate or GPG key set in your profile. To receive attributes in emails, submit your public certificate or GPG key in your profile.'));
			} else if (!Configure::read('SMIME.enabled')) {
				$this->Session->setFlash(__('No GPG key set in your profile. To receive attributes in emails, submit your public key in your profile.'));
			}
		}
		$this->set('eventDescriptions', $this->Event->fieldDescriptions);
		$this->set('analysisLevels', $this->Event->analysisLevels);
		$this->set('distributionLevels', $this->Event->distributionLevels);
		$this->set('shortDist', $this->Event->shortDist);
		if ($this->request->is('ajax')) {
			$this->autoRender = false;
			$this->layout = false;
			$this->render('ajax/index');
		}
		$this->set('ajax', $this->request->is('ajax'));
	}

	public function filterEventIndex() {
		$passedArgsArray = array();
		$filtering = array(
			'published' => 2,
			'org' => array('OR' => array(), 'NOT' => array()),
			'tag' => array('OR' => array(), 'NOT' => array()),
			'eventid' => array('OR' => array(), 'NOT' => array()),
			'date' => array('from' => "", 'until' => ""),
			'eventinfo' => array('OR' => array(), 'NOT' => array()),
			'threatlevel' => array('OR' => array(), 'NOT' => array()),
			'distribution' => array('OR' => array(), 'NOT' => array()),
			'analysis' => array('OR' => array(), 'NOT' => array()),
			'attribute' => array('OR' => array(), 'NOT' => array()),
			'hasproposal' => 2,
		);

		if ($this->_isSiteAdmin()) $filtering['email'] = array('OR' => array(), 'NOT' => array());

		foreach ($this->passedArgs as $k => $v) {
			if (substr($k, 0, 6) === 'search') {
				$searchTerm = substr($k, 6);
				switch ($searchTerm) {
					case 'published' :
					case 'hasproposal' :
						$filtering[$searchTerm] = $v;
						break;
					case 'Datefrom' :
						$filtering['date']['from'] = $v;
						break;
					case 'Dateuntil' :
						$filtering['date']['until'] = $v;
						break;
					case 'email':
					case 'org' :
					case 'eventid' :
					case 'tag' :
					case 'eventinfo' :
					case 'attribute' :
					case 'threatlevel' :
					case 'distribution' :
					case 'analysis' :
						if ($v == "" || ($searchTerm == 'email' && !$this->_isSiteAdmin())) continue 2;
						$pieces = explode('|', $v);
						foreach ($pieces as $piece) {
							if ($piece[0] == '!') {
								$filtering[$searchTerm]['NOT'][] = substr($piece, 1);
							} else {
								$filtering[$searchTerm]['OR'][] = $piece;
							}
						}
						break;
				}
				$passedArgsArray[$searchTerm] = $v;
			}
		}
		$this->set('filtering', json_encode($filtering));
		$tags = $this->Event->EventTag->Tag->find('all', array('recursive' => -1));
		$tagNames = array();
		$tagJSON = array();
		foreach ($tags as $k => $v) {
			$tagNames[$v['Tag']['id']] = $v['Tag']['name'];
			$tagJSON[] = array('id' => $v['Tag']['id'], 'value' => h($v['Tag']['name']));
		}
		$conditions = array();
		if (!$this->_isSiteAdmin()) {
			$eIds = $this->Event->fetchEventIds($this->Auth->user(), false, false, false, true);
			$conditions['AND'][] = array('Event.id' => $eIds);
		}
		$rules = array('published', 'eventid', 'tag', 'date', 'eventinfo', 'threatlevel', 'distribution', 'analysis', 'attribute', 'hasproposal');
		if ($this->_isSiteAdmin()) $rules[] = 'email';
		if (Configure::read('MISP.showorg')) {
			$orgs = $this->Event->Orgc->find('list', array(
				'conditions' => array(),
				'recursive' => -1,
				'fields' => array('Orgc.id', 'Orgc.name'),
				'sort' => array('lower(Orgc.name) asc')
			));
			$this->set('showorg', true);
			$this->set('orgs', $orgs);
			$rules[] = 'org';
		} else {
			$this->set('showorg', false);
		}
		$rules = $this->_arrayToValuesIndexArray($rules);
		$this->set('tags', $tagNames);
		$this->set('tagJSON', json_encode($tagJSON));
		$this->set('rules', $rules);
		$this->set('baseurl', Configure::read('MISP.baseurl'));
		$this->layout = 'ajax';
	}

	/*
	 * Search for a value on an attribute level for a specific field.
	 * $attribute : (array) an attribute
	 * $fields : (array) list of keys in attribute to search in
	 * $searchValue : Value to search
	 * returns true on match
	 */
	 private function __valueInFieldAttribute($attribute, $fields, $searchValue) {
		foreach ($attribute as $k => $v){ // look in attributes line
			if (is_string($v)) {
				foreach ($fields as $field){
					if (strpos(".", $field) === false) { // check sub array after
						// check for key in attribut
						if (isset($attribute[$field])) {
							$temp_value = strtolower($attribute[$field]);
							$temp_search = strtolower($searchValue);
							if(strpos($temp_value, $temp_search) !==false) {
								return true;
							}
						}
					}
				}
			} else {
				// check for tag in attribut maybe for other thing later
				if($k === 'AttributeTag'){
					foreach ($v as $tag) {
						foreach ($fields as $field) {
							if (strpos(strtolower($field), "tag.") !== false) { // check sub array
								$tagKey = explode('tag.', strtolower($field))[1];
								if (isset($tag['Tag'][$tagKey])) {
									$temp_value = strtolower($tag['Tag'][$tagKey]);
									$temp_search = strtolower($searchValue);
									if (strpos($temp_value, $temp_search) !==false) {
										return true;
									}
								}
							}
						}
					}
				}
			}
		}
		return false;
	}

	public function viewEventAttributes($id, $all = false) {
		if (isset($this->params['named']['focus'])) {
			$this->set('focus', $this->params['named']['focus']);
		}
		if (!empty($this->params['named']['overrideLimit'])) {
			$conditions['overrideLimit'] = 1;
		}
		$conditions = array('eventid' => $id);
		if (isset($this->params['named']['deleted']) && $this->params['named']['deleted']) {
			$conditions['deleted'] = 1;
		}
		$conditions['includeFeedCorrelations'] = true;
		$results = $this->Event->fetchEvent($this->Auth->user(), $conditions);
		if (empty($results)) throw new NotFoundException('Invalid event');
		$event = $results[0];

		if (!empty($this->params['named']['searchFor'])) {
			$filterColumns = empty(Configure::read('MISP.event_view_filter_fields')) ? 'id, uuid, value, comment, type, category, Tag.name' : Configure::read('MISP.event_view_filter_fields');
			$filterValue = array_map('trim', explode(",", $filterColumns));
			$validFilters = array('id', 'uuid', 'value', 'comment', 'type', 'category', 'Tag.name');
			foreach ($filterValue as $k => $v) {
				if (!in_array($v, $validFilters)) {
					unset($filterValue[$k]);
				}
			}

			// search in all attributes
			foreach ($event['Attribute'] as $k => $attribute) {
				if (!$this->__valueInFieldAttribute($attribute, $filterValue, $this->params['named']['searchFor'])) {
					unset($event['Attribute'][$k]);
				}
			}
			$event['Attribute'] = array_values($event['Attribute']);

			// search in all attributes
			foreach ($event['ShadowAttribute'] as $k => $proposals) {
				if (!$this->__valueInFieldAttribute($proposals, $filterValue, $this->params['named']['searchFor'])) {
					unset($event['ShadowAttribute'][$k]);
				}
			}
			$event['ShadowAttribute'] = array_values($event['ShadowAttribute']);

			// search for all attributes in object
			foreach ($event['Object'] as $k => $object) {
				foreach ($object['Attribute'] as $k2 => $attribute){
					if (!$this->__valueInFieldAttribute($attribute, $filterValue, $this->params['named']['searchFor'])) {
						unset($event['Object'][$k]['Attribute'][$k2]);
					}
				}
				if (count($event['Object'][$k]['Attribute']) == 0){
					// remove object if empty
					unset($event['Object'][$k]);
				} else {
					$event['Object'][$k]['Attribute'] = array_values($event['Object'][$k]['Attribute']);
				}
			}
			$event['Object'] = array_values($event['Object']);
			$this->set('passedArgsArray', array('all' => $this->params['named']['searchFor']));
		}
		$emptyEvent = (empty($event['Object']) && empty($event['Attribute']));
		$this->set('emptyEvent', $emptyEvent);
		$params = $this->Event->rearrangeEventForView($event, $this->passedArgs, $all);
		$this->params->params['paging'] = array($this->modelClass => $params);
		// workaround to get the event dates in to the attribute relations
		$relatedDates = array();
		if (isset($event['RelatedEvent'])) {
			foreach ($event['RelatedEvent'] as $relation) {
				$relatedDates[$relation['Event']['id']] = $relation['Event']['date'];
			}
			if (isset($event['RelatedAttribute'])) {
				foreach ($event['RelatedAttribute'] as $key => $relatedAttribute) {
					foreach ($relatedAttribute as $key2 => $relation) {
						$event['RelatedAttribute'][$key][$key2]['date'] = $relatedDates[$relation['id']];
					}
				}
			}
		}
		$this->set('event', $event);
		$dataForView = array(
				'Attribute' => array('attrDescriptions', 'typeDefinitions', 'categoryDefinitions', 'distributionDescriptions', 'distributionLevels', 'shortDist'),
				'Event' => array('fieldDescriptions')
		);
		foreach ($dataForView as $m => $variables) {
			if ($m === 'Event') {
				$currentModel = $this->Event;
			} else if ($m === 'Attribute') {
				$currentModel = $this->Event->Attribute;
			}
			foreach ($variables as $variable) {
				$this->set($variable, $currentModel->{$variable});
			}
		}
		$sightingsData = $this->Event->getSightingData($event);
		$this->set('sightingsData', $sightingsData);
		if (Configure::read('Plugin.Enrichment_services_enable')) {
			$this->loadModel('Module');
			$modules = $this->Module->getEnabledModules($this->Auth->user());
			foreach ($modules as $k => $v) {
				if (isset($v['restrict'])) {
					if (!$this->_isSiteAdmin() && $v['restrict'] != $this->Auth->user('org_id')) {
						unset($modules[$k]);
					}
				}
			}
			$this->set('modules', $modules);
		}
		if (Configure::read('Plugin.Cortex_services_enable')) {
			$this->loadModel('Module');
			$cortex_modules = $this->Module->getEnabledModules($this->Auth->user(), false, 'Cortex');
			$this->set('cortex_modules', $cortex_modules);
		}
		$this->set('deleted', (isset($this->params['named']['deleted']) && $this->params['named']['deleted']) ? true : false);
		$this->set('typeGroups', array_keys($this->Event->Attribute->typeGroupings));
		$this->set('attributeFilter', isset($this->params['named']['attributeFilter']) ? $this->params['named']['attributeFilter'] : 'all');
		$this->disableCache();
		$this->layout = 'ajax';
		$this->loadModel('Sighting');
		$uriArray = explode('/', $this->params->here);
		foreach ($uriArray as $k => $v) {
			if (strpos($v, ':')) {
				$temp = explode(':', $v);
				if ($temp[0] == 'focus') {
					unset($uriArray[$k]);
				}
			}
			$this->params->here = implode('/', $uriArray);
		}
		$this->set('sightingTypes', $this->Sighting->type);
		$this->set('currentUri', $this->params->here);
		$this->render('/Elements/eventattribute');
	}

	private function __viewUI($event, $continue, $fromEvent) {
		$emptyEvent = (empty($event['Object']) && empty($event['Attribute']));
		$this->set('emptyEvent', $emptyEvent);
		$attributeCount = isset($event['Attribute']) ? count($event['Attribute']) : 0;
		$this->set('attribute_count', $attributeCount);
		// set the data for the contributors / history field
		$org_ids = $this->Event->ShadowAttribute->getEventContributors($event['Event']['id']);
		$contributors = $this->Event->Org->find('list', array('fields' => array('Org.name'), 'conditions' => array('Org.id' => $org_ids)));
		if ($this->userRole['perm_publish'] && $event['Event']['orgc_id'] == $this->Auth->user('org_id')) {
			$proposalStatus = false;
			if (isset($event['ShadowAttribute']) && !empty($event['ShadowAttribute'])) $proposalStatus = true;
			if (!$proposalStatus && !empty($event['Attribute'])) {
				foreach ($event['Attribute'] as $temp) {
					if (isset($temp['ShadowAttribute']) && !empty($temp['ShadowAttribute'])) {
						$proposalStatus = true;
					}
				}
			}
			$mess = $this->Session->read('Message');
			if ($proposalStatus && empty($mess)) $this->Session->setFlash('This event has active proposals for you to accept or discard.');
		}
		// set the pivot data
		$this->helpers[] = 'Pivot';
		if ($continue) {
			$data = $this->__continuePivoting($event['Event']['id'], $event['Event']['info'], $event['Event']['date'], $fromEvent);
		} else {
			$data = $this->__startPivoting($event['Event']['id'], $event['Event']['info'], $event['Event']['date']);
		}
		$pivot = $this->Session->read('pivot_thread');
		$this->__arrangePivotVertical($pivot);
		$this->__setDeletable($pivot, $event['Event']['id'], true);
		$this->set('allPivots', $this->Session->read('pivot_thread'));
		$this->set('pivot', $pivot);
		// set data for the view, the event is already set in view()
		$dataForView = array(
				'Attribute' => array('attrDescriptions' => 'fieldDescriptions', 'distributionDescriptions' => 'distributionDescriptions', 'distributionLevels' => 'distributionLevels', 'shortDist' => 'shortDist'),
				'Event' => array('eventDescriptions' => 'fieldDescriptions', 'analysisDescriptions' => 'analysisDescriptions', 'analysisLevels' => 'analysisLevels')
		);

		// workaround to get the event dates in to the attribute relations
		$relatedDates = array();
		if (!empty($event['RelatedEvent'])) {
			foreach ($event['RelatedEvent'] as $relation) {
				$relatedDates[$relation['Event']['id']] = $relation['Event']['date'];
			}
			if (!empty($event['RelatedAttribute'])) {
				foreach ($event['RelatedAttribute'] as $key => $relatedAttribute) {
					foreach ($relatedAttribute as $key2 => $relation) {
						$event['RelatedAttribute'][$key][$key2]['date'] = $relatedDates[$relation['id']];
					}
				}
			}
		}

		foreach ($dataForView as $m => $variables) {
			if ($m === 'Event') {
				$currentModel = $this->Event;
			} else if ($m === 'Attribute') {
				$currentModel = $this->Event->Attribute;
			}
			foreach ($variables as $alias => $variable) {
				$this->set($alias, $currentModel->{$variable});
			}
		}
		$this->loadModel('GalaxyCluster');
		$cluster_names = $this->GalaxyCluster->find('list', array('fields' => array('GalaxyCluster.tag_name'), 'group' => array('GalaxyCluster.tag_name', 'GalaxyCluster.id')));
		foreach ($event['EventTag'] as $k => $eventTag) {
			if (in_array($eventTag['Tag']['name'], $cluster_names)) {
				unset($event['EventTag'][$k]);
			}
		}
		$params = $this->Event->rearrangeEventForView($event);
		$this->params->params['paging'] = array($this->modelClass => $params);
		$this->set('event', $event);
		$dataForView = array(
				'Attribute' => array('attrDescriptions', 'typeDefinitions', 'categoryDefinitions', 'distributionDescriptions', 'distributionLevels'),
				'Event' => array('fieldDescriptions')
		);
		foreach ($dataForView as $m => $variables) {
			if ($m === 'Event') {
				$currentModel = $this->Event;
			} else if ($m === 'Attribute') {
				$currentModel = $this->Event->Attribute;
			}
			foreach ($variables as $variable) {
				$this->set($variable, $currentModel->{$variable});
			}
		}
		if (Configure::read('MISP.delegation')) {
			$this->loadModel('EventDelegation');
			$delegationConditions = array('EventDelegation.event_id' => $event['Event']['id']);
			if (!$this->_isSiteAdmin() && $this->userRole['perm_publish']) {
				$delegationConditions['OR'] = array('EventDelegation.org_id' => $this->Auth->user('org_id'),
													'EventDelegation.requester_org_id' => $this->Auth->user('org_id'));
			}
			$this->set('delegationRequest', $this->EventDelegation->find('first', array('conditions' => $delegationConditions,
																						'recursive' => -1,
																						'contain' => array('Org', 'RequesterOrg'))));
		}
		$sightingsData = $this->Event->getSightingData($event);
		$this->set('sightingsData', $sightingsData);
		if (Configure::read('Plugin.Enrichment_services_enable')) {
			$this->loadModel('Module');
			$modules = $this->Module->getEnabledModules($this->Auth->user());
			if (is_array($modules)) {
				foreach ($modules as $k => $v) {
					if (isset($v['restrict'])) {
						if ($this->_isSiteAdmin() && $v['restrict'] != $this->Auth->user('org_id')) {
							unset($modules[$k]);
						}
					}
				}
			}
			$this->set('modules', $modules);
		}
		if (Configure::read('Plugin.Cortex_services_enable')) {
			$this->loadModel('Module');
			$cortex_modules = $this->Module->getEnabledModules($this->Auth->user(), false, 'Cortex');
			$this->set('cortex_modules', $cortex_modules);
		}
		$this->set('contributors', $contributors);
		$this->set('typeGroups', array_keys($this->Event->Attribute->typeGroupings));
		$this->loadModel('Sighting');
		$this->set('sightingTypes', $this->Sighting->type);
		$attributeUri = '/events/viewEventAttributes/' . $event['Event']['id'];
		foreach ($this->params->named as $k => $v) {
			if (!is_numeric($k)) {
				$attributeUri .= '/' . $v;
			}
		}
		$this->set('currentUri', $attributeUri);
	}

	public function view($id = null, $continue=false, $fromEvent=null) {
		// find the id of the event, change $id to it and proceed to read the event as if the ID was entered.
		if (Validation::uuid($id)) {
			$this->Event->recursive = -1;
			$temp = $this->Event->find('first', array(
				'recursive' => -1,
				'conditions' => array('Event.uuid' => $id),
				'fields' => array('Event.id', 'Event.uuid')
			));
			if ($temp == null) throw new NotFoundException('Invalid event');
			$id = $temp['Event']['id'];
		} else if (!is_numeric($id)) {
			throw new NotFoundException(__('Invalid event id.'));
		}
		$this->Event->id = $id;
		if (!$this->Event->exists()) {
			throw new NotFoundException(__('Invalid event.'));
		}
		$conditions = array('eventid' => $id);
		if (!$this->_isRest()) {
			$conditions['includeAllTags'] = true;
		} else {
			$conditions['includeAttachments'] = true;
		}
		if (isset($this->params['named']['deleted']) && $this->params['named']['deleted']) {
			$conditions['deleted'] = 1;
		}
		if (isset($this->params['named']['public']) && $this->params['named']['public']) {
			$conditions['distribution'] = array(3, 5);
		}
		if (!empty($this->params['named']['overrideLimit']) && !$this->_isRest()) {
			$conditions['overrideLimit'] = 1;
		}
		$conditions['includeFeedCorrelations'] = true;
		$results = $this->Event->fetchEvent($this->Auth->user(), $conditions);
		if (empty($results)) throw new NotFoundException('Invalid event');
		//if the current user is an org admin AND event belongs to his/her org, fetch also the event creator info
		if ($this->userRole['perm_admin'] && !$this->_isSiteAdmin() && ($results[0]['Org']['id'] == $this->Auth->user('org_id'))) {
			$results[0]['User']['email'] = $this->User->field('email', array('id' => $results[0]['Event']['user_id']));
		}
		$event = $results[0];
		if ($this->_isRest()) {
			$this->set('event', $event);
		}
		$this->set('deleted', isset($this->params['named']['deleted']) && $this->params['named']['deleted']);
		if (!$this->_isRest()) $this->__viewUI($event, $continue, $fromEvent);
	}

	private function __startPivoting($id, $info, $date) {
		$this->Session->write('pivot_thread', null);
		$initial_pivot = array('id' => $id, 'info' => $info, 'date' => $date, 'depth' => 0, 'height' => 0, 'children' => array(), 'deletable' => true);
		$this->Session->write('pivot_thread', $initial_pivot);
	}

	private function __continuePivoting($id, $info, $date, $fromEvent) {
		$pivot = $this->Session->read('pivot_thread');
		$newPivot = array('id' => $id, 'info' => $info, 'date' => $date, 'depth' => null, 'children' => array(), 'deletable' => true);
		if (!$this->__checkForPivot($pivot, $id)) {
			$pivot = $this->__insertPivot($pivot, $fromEvent, $newPivot, 0);
		}
		$this->Session->write('pivot_thread', $pivot);
	}

	private function __insertPivot($pivot, $oldId, $newPivot, $depth) {
		$depth++;
		if ($pivot['id'] == $oldId) {
			$newPivot['depth'] = $depth;
			$pivot['children'][] = $newPivot;
			return $pivot;
		}
		if (!empty($pivot['children'])) {
			foreach ($pivot['children'] as $k => $v) {
				$pivot['children'][$k] = $this->__insertPivot($v, $oldId, $newPivot, $depth);
			}
		}
		return $pivot;
	}

	private function __checkForPivot($pivot, $id) {
		if ($id == $pivot['id']) return true;
		foreach ($pivot['children'] as $k => $v) {
			if ($this->__checkForPivot($v, $id)) {
				return true;
			}
		}
		return false;
	}

	private function __arrangePivotVertical(&$pivot) {
		if (empty($pivot)) return null;
		$max = count($pivot['children']) - 1;
		if ($max < 0) $max = 0;
		$temp = 0;
		$pivot['children'] = array_values($pivot['children']);
		foreach ($pivot['children'] as $k => $v) {
			$pivot['children'][$k]['height'] = ($temp+$k)*50;
			$temp += $this->__arrangePivotVertical($pivot['children'][$k]);
			if ($k == $max) $temp = $pivot['children'][$k]['height'] / 50;
		}
		return $temp;
	}

	public function removePivot($id, $eventId, $self = false) {
		$pivot = $this->Session->read('pivot_thread');
		if ($pivot['id'] == $id) {
			$pivot = null;
			$this->Session->write('pivot_thread', null);
			$this->redirect(array('controller' => 'events', 'action' => 'view', $eventId));
		} else {
			$pivot = $this->__doRemove($pivot, $id);
		}
		$this->Session->write('pivot_thread', $pivot);
		$pivot = $this->__arrangePivotVertical($pivot);
		$this->redirect(array('controller' => 'events', 'action' => 'view', $eventId, true, $eventId));
	}

	private function __removeChildren(&$pivot, $id) {
		if ($pivot['id'] == $id) {
			$pivot['children'] = array();
		} else {
			foreach ($pivot['children'] as $k => $v) {
				$this->__removeChildren($v, $id);
			}
		}
	}

	private function __doRemove(&$pivot, $id) {
		foreach ($pivot['children'] as $k => $v) {
			if ($v['id'] == $id) {
				unset($pivot['children'][$k]);
				return $pivot;
			} else {
				$pivot['children'][$k] = $this->__doRemove($pivot['children'][$k], $id);
			}
		}
		return $pivot;
	}

	private function __setDeletable(&$pivot, $id, $root=false) {
		if ($pivot['id'] == $id && !$root) {
			$pivot['deletable'] = false;
			return true;
		}
		if (!empty($pivot['children'])) {
			foreach ($pivot['children'] as $k => $v) {
				$containsCurrent = $this->__setDeletable($pivot['children'][$k], $id);
				if ($containsCurrent && !$root) $pivot['deletable'] = false;
			}
		}
		return !$pivot['deletable'];
	}

	public function add() {
		if (!$this->userRole['perm_add']) {
			throw new MethodNotAllowedException('You don\'t have permissions to create events');
		}
		$sgs = $this->Event->SharingGroup->fetchAllAuthorised($this->Auth->user(), 'name',  1);
		if ($this->request->is('post')) {
			if ($this->_isRest()) {
				if (empty($this->data)) {
					throw new MethodNotAllowedException('No valid event data received.');
				}
				// rearrange the response if the event came from an export
				if (isset($this->request->data['response'])) $this->request->data = $this->request->data['response'];

				// Distribution, reporter for the events pushed will be the owner of the authentication key
				$this->request->data['Event']['user_id'] = $this->Auth->user('id');
			}
			if (!empty($this->data)) {
				$ext = '';
				if (isset($this->data['Event']['submittedgfi'])) {
					App::uses('File', 'Utility');
					$file = new File($this->data['Event']['submittedgfi']['name']);
					$ext = $file->ext();
				}
				if (isset($this->data['Event']['submittedgfi']) && ($ext != 'zip') && $this->data['Event']['submittedgfi']['size'] > 0 &&
						is_uploaded_file($this->data['Event']['submittedgfi']['tmp_name'])) {
					$this->Session->setFlash(__('You may only upload GFI Sandbox zip files.'));
				} else {
					if (!isset($this->request->data['Event']['distribution'])) {
						$this->request->data['Event']['distribution'] = Configure::read('MISP.default_event_distribution') ? Configure::read('MISP.default_event_distribution') : 0;
					}
					if (!isset($this->request->data['Event']['analysis'])) {
						$this->request->data['Event']['analysis'] = 0;
					}
					if (!isset($this->request->data['Event']['threat_level_id'])) {
						$this->request->data['Event']['threat_level_id'] = Configure::read('MISP.default_event_threat_level') ? Configure::read('MISP.default_event_threat_level') : 4;
					}
					if (!isset($this->request->data['Event']['date'])) {
						$this->request->data['Event']['date'] = date('Y-m-d');
					}
					// If the distribution is set to sharing group, check if the id provided is really visible to the user, if not throw an error.
					if ($this->request->data['Event']['distribution'] == 4) {
						if ($this->userRole['perm_sync'] && $this->_isRest()) {
							if (isset($this->request->data['Event']['SharingGroup'])) {
								if (!$this->Event->SharingGroup->checkIfAuthorisedToSave($this->Auth->user(), $this->request->data['Event']['SharingGroup'])) throw new MethodNotAllowedException('Invalid Sharing Group or not authorised. (Sync user is not contained in the Sharing group)');
							} else if (!isset($sgs[$this->request->data['Event']['sharing_group_id']])) {
								throw new MethodNotAllowedException('Invalid Sharing Group or not authorised.');
							}
						} else {
							if (!isset($sgs[$this->request->data['Event']['sharing_group_id']])) throw new MethodNotAllowedException('Invalid Sharing Group or not authorised.');
						}
					} else {
						// If the distribution is set to something "traditional", set the SG id to 0.
						$this->request->data['Event']['sharing_group_id'] = 0;
					}
					// If we are not sync users / site admins, we only allow events to be created for our own org
					// Set the orgc ID as our own orgc ID and unset both the 2.4 and 2.3 style creator orgs
					if ($this->_isRest() && !$this->userRole['perm_sync']) {
						$this->request->data['Event']['orgc_id'] = $this->Auth->user('org_id');
						if (isset($this->request->data['Event']['Orgc'])) {
							unset($this->request->data['Event']['Orgc']);
						}
						if (isset($this->request->data['Event']['orgc'])) {
							unset($this->request->data['Event']['orgc']);
						}
					}
					$validationErrors = array();
					$created_id = 0;
					$add = $this->Event->_add($this->request->data, $this->_isRest(), $this->Auth->user(), '', null, false, null, $created_id, $validationErrors);
					if ($add === true && !is_numeric($add)) {
						if ($this->_isRest()) {
							if ($add === 'blocked') {
								throw new ForbiddenException('Event blocked by local blacklist.');
							}
							// REST users want to see the newly created event
							$results = $this->Event->fetchEvent($this->Auth->user(), array('eventid' => $created_id));
							$event = $results[0];
							if (!empty($validationErrors)) {
								$event['errors'] = $validationErrors;
							}
							$this->set('event', $event);
							$this->render('view');
							return true;
						} else {
							// TODO now save uploaded attributes using $this->Event->getID() ..
							if (isset($this->data['Event']['submittedgfi'])) $this->_addGfiZip($this->Event->getID());

							// redirect to the view of the newly created event
							if (!CakeSession::read('Message.flash')) {
								$this->Session->setFlash(__('The event has been saved'));
							} else {
								$existingFlash = CakeSession::read('Message.flash');
								$this->Session->setFlash(__('The event has been saved. ' . $existingFlash['message']));
							}
							$this->redirect(array('action' => 'view', $this->Event->getID()));
						}
					} else {
						if ($this->_isRest()) { // TODO return error if REST
							if (is_numeric($add)) {
								$this->response->header('Location', Configure::read('MISP.baseurl') . '/events/' . $add);
								$this->response->send();
								throw new NotFoundException('Event already exists, if you would like to edit it, use the url in the location header.');
							}
							$this->set('name', 'Add event failed.');
							$this->set('message', 'The event could not be saved.');
							$this->set('errors', $validationErrors);
							$this->set('url', '/events/add');
							$this->set('_serialize', array('name', 'message', 'url', 'errors'));
							return false;
						} else {
							if ($add === 'blocked') {
								$this->Session->setFlash('A blacklist entry is blocking you from creating any events. Please contact the administration team of this instance' . (Configure::read('MISP.contact') ? ' at ' . Configure::read('MISP.contact') : '') . '.');
							} else {
								$this->Session->setFlash(__('The event could not be saved. Please, try again.'), 'default', array(), 'error');
							}
						}
					}
				}
			}
		}

		$this->request->data['Event']['date'] = date('Y-m-d');

		// combobox for distribution
		$distributions = array_keys($this->Event->distributionDescriptions);
		$distributions = $this->_arrayToValuesIndexArray($distributions);
		$this->set('distributions', $distributions);
		// tooltip for distribution
		$info = array();
		$distributionLevels = $this->Event->distributionLevels;
		if (empty($sgs)) unset($distributionLevels[4]);
		$this->set('distributionLevels', $distributionLevels);
		foreach ($distributionLevels as $key => $value) {
			$info['distribution'][$key] = array('key' => $value, 'desc' => $this->Event->distributionDescriptions[$key]['formdesc']);
		}

		// combobox for risks
		$threat_levels = $this->Event->ThreatLevel->find('all');
		$this->set('threatLevels', Set::combine($threat_levels, '{n}.ThreatLevel.id', '{n}.ThreatLevel.name'));
		foreach ($threat_levels as $key => $threat_level) {
			$info['threat_level'][$threat_level['ThreatLevel']['id']] = array('key' => $threat_level['ThreatLevel']['name'], 'desc' => $threat_level['ThreatLevel']['form_description']);
		}

		// combobox for analysis
		$this->set('sharingGroups', $sgs);
		// tooltip for analysis
		foreach ($this->Event->analysisLevels as $key => $value) {
			$info['analysis'][$key] = array('key' => $value, 'desc' => $this->Event->analysisDescriptions[$key]['formdesc']);
		}
		$this->set('info', $info);
		$this->set('analysisDescriptions', $this->Event->analysisDescriptions);
		$this->set('analysisLevels', $this->Event->analysisLevels);
	}

	public function addIOC($id) {
		$this->Event->recursive = -1;
		$this->Event->read(null, $id);
		if (!$this->_isSiteAdmin() && ($this->Event->data['Event']['orgc_id'] != $this->_checkOrg() || !$this->userRole['perm_modify'])) {
			throw new UnauthorizedException('You do not have permission to do that.');
		}
		if ($this->request->is('post')) {
			if (!empty($this->data)) {
				if (isset($this->data['Event']['submittedioc'])) $this->_addIOCFile($id);

				// redirect to the view of the newly created event
				if (!CakeSession::read('Message.flash')) {
					$this->Session->setFlash(__('The event has been saved'));
				} else {
					$existingFlash = CakeSession::read('Message.flash');
					$this->Session->setFlash(__('The event has been saved. ' . $existingFlash['message']));
				}
			}
		}
		// set the id
		$this->set('id', $id);
		// set whether it is published or not
		$this->set('published', $this->Event->data['Event']['published']);
	}

	public function add_misp_export() {
		if (!$this->userRole['perm_modify']) {
			throw new UnauthorizedException('You do not have permission to do that.');
		}
		if ($this->request->is('post')) {
			$results = array();
			if (!empty($this->data)) {
				$ext = '';
				if (isset($this->data['Event']['submittedfile'])) {
					$ext = pathinfo($this->data['Event']['submittedfile']['name'], PATHINFO_EXTENSION);
				}
				if (isset($this->data['Event']['submittedfile']) && (strtolower($ext) != 'xml' && strtolower($ext) != 'json') && $this->data['Event']['submittedfile']['size'] > 0 &&
				is_uploaded_file($this->data['Event']['submittedfile']['tmp_name'])) {
					$log = ClassRegistry::init('Log');
					$log->createLogEntry($this->Auth->user(), 'file_upload', 'Event', 0, 'MISP export file upload failed', 'File details: ' . json_encode($this->data['Event']['submittedfile']));
					$this->Session->setFlash(__('You may only upload MISP XML or MISP JSON files.'));
					throw new MethodNotAllowedException('File upload failed or file does not have the expected extension (.xml / .json).');
				}
				if (isset($this->data['Event']['submittedfile'])) {
					if (Configure::read('MISP.take_ownership_xml_import')
						&& (isset($this->data['Event']['takeownership']) && $this->data['Event']['takeownership'] == 1)) {
						$results = $this->_addMISPExportFile($ext, true, $this->data['Event']['publish']);
					} else {
						$results = $this->_addMISPExportFile($ext, false, $this->data['Event']['publish']);
					}
				}
			}
			$this->set('results', $results);
			$this->render('add_misp_export_result');
		}
	}

	public function merge($target_id = null) {
		$this->Event->id = $target_id;
		$eIds = $this->Event->fetchEventIds($this->Auth->user(), false, false, false, true);
		// check if event exists and is readable for the current user
		if (!$this->Event->exists() || !in_array($target_id, $eIds)) {
			throw new NotFoundException(__('Invalid event'));
		}
		$this->Event->read(null, $target_id);
		// check if private and user not authorised to edit
		if (!$this->_isSiteAdmin() && ($this->Event->data['Event']['orgc_id'] != $this->_checkOrg() || !($this->userRole['perm_modify']))) {
			$this->Session->setFlash(__('You are not authorised to do that. Please consider using the \'propose attribute\' feature.'));
			$this->redirect(array('action' => 'view', $target_id));
		}
		if ($this->request->is('post')) {
			$source_id = $this->request->data['Event']['source_id'];
			$to_ids = $this->request->data['Event']['to_ids'];
			if (!is_numeric($source_id)) {
				$this->Session->setFlash(__('Invalid event ID entered.'));
				return;
			}
			$this->Event->read(null, $source_id);
			if (!$this->_isSiteAdmin() && !in_array($source_id, $eIds)) {
				$this->Session->setFlash(__('You are not authorised to read the selected event.'));
				return;
			}
			$r = array('results' => []);
			foreach ($this->Event->data['Attribute'] as $a) {
				if ($to_ids && !$a['to_ids']) {
					continue;
				}
				$tmp = array();
				$tmp['values']     = $a['value'];
				$tmp['categories'] = $a['category'];
				$tmp['types']      = $a['type'];
				$tmp['to_ids']     = $a['to_ids'];
				$tmp['comment']    = $a['comment'];
				if ($this->Event->Attribute->typeIsAttachment($a['type'])) {
					$encodedFile = $this->Event->Attribute->base64EncodeAttachment($a);
					$tmp['data'] = $encodedFile;
					$tmp['data_is_handled'] = true;
				}
				$r['results'][] = $tmp;
			}
			$resultArray = $this->Event->handleModuleResult($r, $target_id);
			$typeCategoryMapping = array();
			foreach ($this->Event->Attribute->categoryDefinitions as $k => $cat) {
				foreach ($cat['types'] as $type) {
					$typeCategoryMapping[$type][$k] = $k;
				}
			}
			foreach ($resultArray as $key => $result) {
				$options = array(
						'conditions' => array('OR' => array('Attribute.value1' => $result['value'], 'Attribute.value2' => $result['value'])),
						'fields' => array('Attribute.type', 'Attribute.category', 'Attribute.value', 'Attribute.comment'),
						'order' => false
				);
				$resultArray[$key]['related'] = $this->Event->Attribute->fetchAttributes($this->Auth->user(), $options);
			}
			$this->set('event', array('Event' => array('id' => $target_id)));
			$this->set('resultArray', $resultArray);
			$this->set('typeList', array_keys($this->Event->Attribute->typeDefinitions));
			$this->set('defaultCategories', $this->Event->Attribute->defaultCategories);
			$this->set('typeCategoryMapping', $typeCategoryMapping);
			$this->set('title', 'Merge Results');
			$this->set('importComment', 'Merged from event ' . $source_id);
			$this->render('resolved_attributes');
		} else {
			// set the target event id in the form
			$this->request->data['Event']['target_id'] = $target_id;
		}
	}

	public function edit($id = null) {
		if (Validation::uuid($id)) {
			$temp = $this->Event->find('first', array('recursive' => -1, 'fields' => array('Event.id'), 'conditions' => array('Event.uuid' => $id)));
			if (empty($temp)) throw new NotFoundException('Invalid event');
			$id = $temp['Event']['id'];
		} else if (!is_numeric($id)) {
			throw new NotFoundException(__('Invalid event'));
		}
		$this->Event->id = $id;
		if (!$this->Event->exists()) {
			throw new NotFoundException(__('Invalid event'));
		}
		$this->Event->read(null, $id);
		// check if private and user not authorised to edit
		if (!$this->_isSiteAdmin() && !($this->userRole['perm_sync'] && $this->_isRest())) {
			if (($this->Event->data['Event']['orgc_id'] != $this->_checkOrg()) || !($this->userRole['perm_modify'])) {
				$this->Session->setFlash(__('You are not authorised to do that. Please consider using the \'propose attribute\' feature.'));
				$this->redirect(array('controller' => 'events', 'action' => 'index'));
			}
		}
		if ($this->request->is('post') || $this->request->is('put')) {
			if ($this->_isRest()) {
				if ($this->_isRest()) {
					if (isset($this->request->data['response'])) {
						$this->request->data = $this->Event->updateXMLArray($this->request->data, true);
					} else {
						$this->request->data = $this->Event->updateXMLArray($this->request->data, false);
					}
				}
				// Workaround for different structure in XML/array than what CakePHP expects
				if (isset($this->request->data['response'])) $this->request->data = $this->request->data['response'];
				if (!isset($this->request->data['Event'])) $this->request->data = array('Event' => $this->request->data);
				$result = $this->Event->_edit($this->request->data, $this->Auth->user(), $id);
				if ($result === true) {
					// REST users want to see the newly created event
					$results = $this->Event->fetchEvent($this->Auth->user(), array('eventid' => $id));
					$event = $results[0];
					$this->set('event', $event);
					$this->render('view');
					return true;
				} else {
					$message = 'Error';
					if ($this->_isRest()) {
						App::uses('JSONConverterTool', 'Tools');
						$converter = new JSONConverterTool();
						if (isset($result['error'])) {
							$errors = $result['error'];
						} else {
							$errors = $converter->arrayPrinter($result);
						}
						$this->set('name', 'Edit event failed.');
						$this->set('message', $message);
						$this->set('errors', $errors);
						$this->set('url', '/events/edit/' . $id);
						$this->set('_serialize', array('name', 'message', 'url', 'errors'));
					} else {
						$this->set(array('message' => $message,'_serialize' => array('message')));	// $this->Event->validationErrors
						$this->render('edit');
					}
					return false;
				}
			}
			// say what fields are to be updated
			$fieldList = array('date', 'threat_level_id', 'analysis', 'info', 'published', 'distribution', 'timestamp', 'sharing_group_id');

			$this->Event->read();
			// always force the org, but do not force it for admins
			if (!$this->_isSiteAdmin()) {
				// set the same org as existed before
				$this->request->data['Event']['org_id'] = $this->Event->data['Event']['org_id'];
			}
			// we probably also want to remove the published flag
			$this->request->data['Event']['published'] = 0;
			$date = new DateTime();
			$this->request->data['Event']['timestamp'] = $date->getTimestamp();
			if ($this->Event->save($this->request->data, true, $fieldList)) {
				$this->Session->setFlash(__('The event has been saved'));
				$this->redirect(array('action' => 'view', $id));
			} else {
				$this->Session->setFlash(__('The event could not be saved. Please, try again.'));
			}
		} else {
			if (!$this->userRole['perm_modify']) $this->redirect(array('controller' => 'events', 'action' => 'index', 'admin' => false));
			$this->request->data = $this->Event->read(null, $id);
		}

		// combobox for distribution
		$distributions = array_keys($this->Event->distributionDescriptions);
		$distributions = $this->_arrayToValuesIndexArray($distributions);
		$this->set('distributions', $distributions);

		// even if the SG is not local, we still want the option to select the currently assigned SG
		$sgs = $this->Event->SharingGroup->fetchAllAuthorised($this->Auth->user(), 'name',  1);
		$this->set('sharingGroups', $sgs);

		// tooltip for distribution
		$info = array();
		$distributionLevels = $this->Event->distributionLevels;
		if (empty($sgs)) unset($distributionLevels[4]);
		$this->set('distributionLevels', $distributionLevels);
		foreach ($distributionLevels as $key => $value) {
			$info['distribution'][$key] = array('key' => $value, 'desc' => $this->Event->distributionDescriptions[$key]['formdesc']);
		}

		// combobox for risks
		$threat_levels = $this->Event->ThreatLevel->find('all');
		$this->set('threatLevels', Set::combine($threat_levels, '{n}.ThreatLevel.id', '{n}.ThreatLevel.name'));
		foreach ($threat_levels as $key => $threat_level) {
			$info['threat_level'][$threat_level['ThreatLevel']['id']] = array('key' => $threat_level['ThreatLevel']['name'], 'desc' => $threat_level['ThreatLevel']['form_description']);
		}

		// combobox for analysis
		$this->set('sharingGroups', $sgs);
		// tooltip for analysis
		foreach ($this->Event->analysisLevels as $key => $value) {
			$info['analysis'][$key] = array('key' => $value, 'desc' => $this->Event->analysisDescriptions[$key]['formdesc']);
		}
		$this->set('analysisLevels', $this->Event->analysisLevels);

		$this->set('info', $info);
		$this->set('eventDescriptions', $this->Event->fieldDescriptions);
		$this->set('event', $this->Event->data);
	}

	public function delete($id = null) {
		if ($this->request->is('post') || $this->request->is('put') || $this->request->is('delete')) {
			if (isset($this->request->data['id'])) {
				$this->request->data['Event'] = $this->request->data;
			}
			if (!isset($id) && isset($this->request->data['Event']['id'])) {
				$idList = $this->request->data['Event']['id'];
				if (!is_array($idList)) {
					if (is_numeric($idList)) {
						$idList = array($idList);
					} else {
						$idList = json_decode($idList, true);
					}
				}
				if (!is_array($idList) || empty($idList)) {
					throw new NotFoundException(__('Invalid input.'));
				}
			} else {
				$idList = array($id);
			}
			$fails = array();
			$successes = array();
			foreach ($idList as $eid) {
				if (!is_numeric($eid)) continue;
				$event = $this->Event->find('first', array(
					'conditions' => array('Event.id' => $eid),
					'fields' => array('Event.orgc_id', 'Event.id'),
					'recursive' => -1
				));
				if (empty($event)) {
					$fails[] = $eid;
				} else {
					if (!$this->_isSiteAdmin()) {
						if ($event['Event']['orgc_id'] != $this->_checkOrg() || !$this->userRole['perm_modify']) {
							$fails[] = $eid;
							continue;
						}
					}
					if ($this->Event->quickDelete($event)) {
						$successes[] = $eid;
					} else {
						$fails[] = $eid;
					}
				}
			}
			$message = '';
			if (count($idList) == 1) {
				if (!empty($successes)) {
					$message = 'Event deleted.';
				} else {
					$message = 'Event was not deleted.';
				}
			} else {
				if (!empty($successes)) {
					$message .= count($successes) . ' event(s) deleted.';
				}
				if (!empty($fails)) {
					$message .= count($fails) . ' event(s) could not be deleted due to insufficient privileges or the event not being found.';
				}
			}
			if ($this->_isRest()) {
				if (!empty($successes)) {
					return $this->RestResponse->saveSuccessResponse('Events', 'delete', $id, $this->response->type(), $message);
				} else {
					return $this->RestResponse->saveFailResponse('Events', 'delete', false, $message, $this->response->type());
				}
			} else {
				$this->Session->setFlash($message);
				$this->redirect(array('action' => 'index'));
			}
		} else {
			if (is_numeric($id)) {
				$eventList = array($id);
			} else {
				$eventList = json_decode($id, true);
			}
			$this->request->data['Event']['id'] = json_encode($eventList);
			$this->set('idArray', $eventList);
			$this->render('ajax/eventDeleteConfirmationForm');
		}
	}

	// Publishes the event without sending an alert email
	public function publish($id = null) {
		$this->Event->id = $id;
		if (!$this->Event->exists()) {
			throw new NotFoundException(__('Invalid event'));
		}
		// update the event and set the from field to the current instance's organisation from the bootstrap. We also need to save id and info for the logs.
		$this->Event->recursive = -1;
		$event = $this->Event->read(null, $id);
		if (!$this->_isSiteAdmin()) {
			if (!$this->userRole['perm_publish'] || $this->Auth->user('org_id') !== $this->Event->data['Event']['orgc_id']) {
				throw new MethodNotAllowedException('You don\'t have the permission to do that.');
			}
		}
		$success = true;
		$message = '';
		$errors = array();
		// only allow form submit CSRF protection.
		if ($this->request->is('post') || $this->request->is('put')) {
			// Performs all the actions required to publish an event
			$result = $this->Event->publishRouter($id, null, $this->Auth->user());
			if (!Configure::read('MISP.background_jobs')) {
				if (!is_array($result)) {
					// redirect to the view event page
					$message = 'Event published without alerts';
				} else {
					$lastResult = array_pop($result);
					$resultString = (count($result) > 0) ? implode(', ', $result) . ' and ' . $lastResult : $lastResult;
					$errors['failed_servers'] = $result;
					$message = sprintf('Event published but not pushed to %s, re-try later. If the issue persists, make sure that the correct sync user credentials are used for the server link and that the sync user on the remote server has authentication privileges.', $resultString);
				}
			} else {
				// update the DB to set the published flag
				// for background jobs, this should be done already
				$fieldList = array('published', 'id', 'info', 'publish_timestamp');
				$event['Event']['published'] = 1;
				$event['Event']['publish_timestamp'] = time();
				$this->Event->save($event, array('fieldList' => $fieldList));
				$message = 'Job queued';
			}
			if ($this->_isRest()) {
				$this->set('name', 'Publish');
				$this->set('message', $message);
				if (!empty($errors)) {
					$this->set('errors', $errors);
				}
				$this->set('url', '/events/alert/' . $id);
				$this->set('id', $id);
				$this->set('_serialize', array('name', 'message', 'url', 'id', 'errors'));
			} else {
				$this->Session->setFlash($message);
				$this->redirect(array('action' => 'view', $id));
			}
		} else {
			$this->set('id', $id);
			$this->set('type', 'publish');
			$this->render('ajax/eventPublishConfirmationForm');
		}
	}

	// Send out an alert email to all the users that wanted to be notified.
	// Users with a GPG key will get the mail encrypted, other users will get the mail unencrypted
	public function alert($id = null) {
		$this->Event->id = $id;
		$this->Event->recursive = 0;
		if (!$this->Event->exists()) {
			throw new NotFoundException(__('Invalid event'));
		}
		$this->Event->recursive = -1;
		$this->Event->read(null, $id);
		if (!$this->_isSiteAdmin()) {
			if (!$this->userRole['perm_publish'] || $this->Auth->user('org_id') !== $this->Event->data['Event']['orgc_id']) {
				throw new MethodNotAllowedException('You don\'t have the permission to do that.');
			}
		}
		$success = true;
		$message = '';
		$errors = array();
		// only allow form submit CSRF protection
		if ($this->request->is('post') || $this->request->is('put')) {
			// send out the email
			$emailResult = $this->Event->sendAlertEmailRouter($id, $this->Auth->user(), $this->Event->data['Event']['publish_timestamp']);
			if (is_bool($emailResult) && $emailResult == true) {
				// Performs all the actions required to publish an event
				$result = $this->Event->publishRouter($id, null, $this->Auth->user());
				if (!is_array($result)) {
					// redirect to the view event page
					if (Configure::read('MISP.background_jobs')) {
						$message = 'Job queued.';
					} else {
						$message = 'Email sent to all participants.';
					}
				} else {
					$lastResult = array_pop($result);
					$resultString = (count($result) > 0) ? implode(', ', $result) . ' and ' . $lastResult : $lastResult;
					$errors['failed_servers'] = $result;
					$message = sprintf('Not published given no connection to %s but email sent to all participants.', $resultString);
				}
			} else if (!is_bool($emailResult)) {
				// Performs all the actions required to publish an event
				$result = $this->Event->publishRouter($id, null, $this->Auth->user());
				if (!is_array($result)) {
					// redirect to the view event page
					$message = 'Published but no email sent given GnuPG is not configured.';
					$errors['GnuPG'] = 'GnuPG not set up.';
				} else {
					$lastResult = array_pop($result);
					$resultString = (count($result) > 0) ? implode(', ', $result) . ' and ' . $lastResult : $lastResult;
					$errors['failed_servers'] = $result;
					$errors['GnuPG'] = 'GnuPG not set up.';
					$message = sprintf('Not published given no connection to %s but no email sent given GnuPG is not configured.', $resultString);
				}
			} else {
				$message = 'Sending of email failed';
				$errors['email'] = 'The sending of emails failed.';
			}
			if ($this->_isRest()) {
				$this->set('name', 'Alert');
				$this->set('message', $message);
				if (!empty($errors)) {
					$this->set('errors', $errors);
				}
				$this->set('url', '/events/alert/' . $id);
				$this->set('id', $id);
				$this->set('_serialize', array('name', 'message', 'url', 'id', 'errors'));
			} else {
				$this->Session->setFlash($message);
				$this->redirect(array('action' => 'view', $id));
			}
		} else {
			$this->set('id', $id);
			$this->set('type', 'alert');
			$this->render('ajax/eventPublishConfirmationForm');
		}
	}

	// Send out an contact email to the person who posted the event.
	// Users with a GPG key will get the mail encrypted, other users will get the mail unencrypted
	public function contact($id = null) {
		$this->Event->id = $id;
		if (!$this->Event->exists()) {
			throw new NotFoundException(__('Invalid event'));
		}
		// User has filled in his contact form, send out the email.
		if ($this->request->is('post') || $this->request->is('put')) {
			$message = $this->request->data['Event']['message'];
			$creator_only = $this->request->data['Event']['person'];
			$user = $this->Auth->user();
			$user['gpgkey'] = $this->Event->User->getPGP($user['id']);
			$user['certif_public'] = $this->Event->User->getCertificate($user['id']);
			if ($this->Event->sendContactEmailRouter($id, $message, $creator_only, $user, $this->_isSiteAdmin())) {
				// redirect to the view event page
				$this->Session->setFlash(__('Email sent to the reporter.', true));
			} else {
				$this->Session->setFlash(__('Sending of email failed', true), 'default', array(), 'error');
			}
			$this->redirect(array('action' => 'view', $id));
		}
		// User didn't see the contact form yet. Present it to him.
		if (empty($this->data)) {
			$this->data = $this->Event->read(null, $id);
		}
	}

	public function automation() {
		// Simply display a static view
		if (!$this->userRole['perm_auth']) {
			$this->redirect(array('controller' => 'events', 'action' => 'index'));
		}
		App::uses('BroExport', 'Export');
		$export = new BroExport();
		$temp = $export->mispTypes;
		$broTypes = array('all' => 'All types listed below.');
		foreach ($temp as $broType => $mispTypes) {
			foreach ($mispTypes as $mT) {
				$broTypes[$broType][] = $mT[0];
			}
			$broTypes[$broType] = implode(', ', $broTypes[$broType]);
		}
		$this->set('broTypes', $broTypes);
		// generate the list of Attribute types
		$this->loadModel('Attribute');
		$this->set('sigTypes', array_keys($this->Attribute->typeDefinitions));
		$this->loadModel('Server');
		$rpzSettings = $this->Server->retrieveCurrentSettings('Plugin', 'RPZ_');
		$this->set('rpzSettings', $rpzSettings);
		$this->set('hashTypes', array_keys($this->Event->Attribute->hashTypes));
	}

	public function export() {
		$filesize_units = array('B', 'KB', 'MB', 'GB', 'TB');
		if ($this->_isSiteAdmin()) $this->Session->setFlash('Warning, you are logged in as a site admin, any export that you generate will contain the FULL UNRESTRICTED data-set. If you would like to generate an export for your own organisation, please log in with a different user.');
		// Check if the background jobs are enabled - if not, fall back to old export page.
		if (Configure::read('MISP.background_jobs') && !Configure::read('MISP.disable_cached_exports')) {
			$now = time();

			// as a site admin we'll use the ADMIN identifier, not to overwrite the cached files of our own org with a file that includes too much data.
			$org_name = $this->_isSiteAdmin() ? 'ADMIN' : $this->Auth->user('Organisation')['name'];
			$conditions = $this->Event->buildEventConditions($this->Auth->user());
			$this->Event->recursive = -1;
			$newestEvent = $this->Event->find('first', array(
				'conditions' => $conditions,
				'fields' => 'timestamp',
				'order' => 'Event.timestamp DESC',
			));
			$newestEventPublished = $this->Event->find('first', array(
				'conditions' => array('AND' => array($conditions, array('published' => 1))),
				'fields' => 'timestamp',
				'order' => 'Event.timestamp DESC',
			));
			$this->loadModel('Job');
			foreach ($this->Event->export_types as $k => $type) {
				if ($type['requiresPublished']) {
					$tempNewestEvent = $newestEventPublished;
				} else {
					$tempNewestEvent = $newestEvent;
				}
				$job = $this->Job->find('first', array(
						'fields' => array('id', 'progress'),
						'conditions' => array(
								'job_type' => 'cache_' . $k,
								'org_id' => $this->_isSiteAdmin() ? 0 : $this->Auth->user('org_id')
							),
						'order' => array('Job.id' => 'desc')
				));
				$dir = new Folder(APP . 'tmp/cached_exports/' . $k);
				if ($k === 'text') {
					// Since all of the text export files are generated together, we might as well just check for a single one md5.
					$file = new File($dir->pwd() . DS . 'misp.text_md5.' . $org_name . $type['extension']);
				} else {
					$file = new File($dir->pwd() . DS . 'misp.' . $k . '.' . $org_name . $type['extension']);
				}
				if (!$file->readable()) {
					if (empty($tempNewestEvent)) {
						$lastModified = 'No valid events';
						$this->Event->export_types[$k]['recommendation'] = 0;
					} else {
						$lastModified = 'N/A';
						$this->Event->export_types[$k]['recommendation'] = 1;
					}
				} else {
					$filesize = $file->size();
					$filesize_unit_index = 0;
					while ($filesize > 1024) {
						$filesize_unit_index++;
						$filesize = $filesize / 1024;
					}
					$this->Event->export_types[$k]['filesize'] = round($filesize, 1) . $filesize_units[$filesize_unit_index];
					$fileChange = $file->lastChange();
					$lastModified = $this->__timeDifference($now, $fileChange);
					if (empty($tempNewestEvent) || $fileChange > $tempNewestEvent['Event']['timestamp']) {
						if (empty($tempNewestEvent)) {
							$lastModified = 'No valid events';
						}
						$this->Event->export_types[$k]['recommendation'] = 0;
					} else {
						$this->Event->export_types[$k]['recommendation'] = 1;
					}
				}

				$this->Event->export_types[$k]['lastModified'] = $lastModified;
				if (!empty($job)) {
					$this->Event->export_types[$k]['job_id'] = $job['Job']['id'];
					$this->Event->export_types[$k]['progress'] = $job['Job']['progress'];
				} else {
					$this->Event->export_types[$k]['job_id'] = -1;
					$this->Event->export_types[$k]['progress'] = 0;
				}
			}
			$this->set('export_types', $this->Event->export_types);
			// generate the list of Attribute types
			$this->loadModel('Attribute');
			$this->set('sigTypes', array_keys($this->Attribute->typeDefinitions));
		} else {
			// generate the list of Attribute types
			$this->loadModel('Attribute');
			$this->set('sigTypes', array_keys($this->Attribute->typeDefinitions));
			$this->render('/Events/export_alternate');
		}
	}

	public function downloadExport($type, $extra = null) {
		if (Configure::read('MISP.disable_cached_exports')) {
			throw new MethodNotAllowedException('This feature is currently disabled');
		}
		if ($this->_isSiteAdmin()) $org = 'ADMIN';
		else $org = $this->Auth->user('Organisation')['name'];
		$this->autoRender = false;
		if ($extra != null) $extra = '_' . $extra;
		$this->response->type($this->Event->export_types[$type]['extension']);
		$path = 'tmp/cached_exports/' . $type . DS . 'misp.' . strtolower($this->Event->export_types[$type]['type']) . $extra . '.' . $org . $this->Event->export_types[$type]['extension'];
		$this->response->file($path, array('download' => true));
	}

	private function __timeDifference($now, $then) {
		$periods = array("second", "minute", "hour", "day", "week", "month", "year");
		$lengths = array("60","60","24","7","4.35","12");
		$difference = $now - $then;
		for ($j = 0; $difference >= $lengths[$j] && $j < count($lengths)-1; $j++) {
			$difference /= $lengths[$j];
		}
		$difference = round($difference);
		if ($difference != 1) {
			$periods[$j].= "s";
		}
		return $difference . " " . $periods[$j] . " ago";
	}

	public function xml($key, $eventid = false, $withAttachment = false, $tags = false, $from = false, $to = false, $last = false) {
		App::uses('XMLConverterTool', 'Tools');
		$converter = new XMLConverterTool();
		$this->loadModel('Whitelist');

		// request handler for POSTed queries. If the request is a post, the parameters (apart from the key) will be ignored and replaced by the terms defined in the posted xml object.
		// The correct format for a posted xml is a "request" root element, as shown by the examples below:
		// For XML: <request><value>7.7.7.7&amp;&amp;1.1.1.1</value><type>ip-src</type></request>
		if ($this->request->is('post')) {
			if (empty($this->request->data)) {
				throw new BadRequestException('Either specify the search terms in the url, or POST an xml (with the root element being "request".');
			} else {
				$data = $this->request->data;
			}
			$paramArray = array('eventid', 'withAttachment', 'tags', 'from', 'to', 'last');
			foreach ($paramArray as $p) {
				if (isset($data['request'][$p])) {
					${$p} = $data['request'][$p];
				} else {
					${$p} = null;
				}
			}
		}

		$simpleFalse = array('tags', 'eventid', 'withAttachment', 'from', 'to', 'last');
		foreach ($simpleFalse as $sF) {
			if (!is_array(${$sF}) && (${$sF} === 'null' || ${$sF} == '0' || ${$sF} === false || strtolower(${$sF}) === 'false')) {
				${$sF} = false;
			}
		}
		if ($from) $from = $this->Event->dateFieldCheck($from);
		if ($to) $to = $this->Event->dateFieldCheck($to);
		if ($tags) $tags = str_replace(';', ':', $tags);
		if ($last) $last = $this->Event->resolveTimeDelta($last);
		$eventIdArray = array();

		if ($eventid) {
			if (!is_numeric($eventid)) throw new MethodNotAllowedException('Invalid Event ID.');
			$eventIdArray[] = $eventid;
		}

		if ($key != 'download') {
			// check if the key is valid -> search for users based on key
			$user = $this->checkAuthUser($key);
			if (!$user) {
				throw new UnauthorizedException('This authentication key is not authorized to be used for exports. Contact your administrator.');
			}
		} else {
			if (!$this->Auth->user('id')) {
				throw new UnauthorizedException('You have to be logged in to do that.');
			}
			$user = $this->Auth->user();
		}

		if ($eventid) {
			$final_filename='misp.event' . $eventid . '.export.xml';
		} else {
			$final_filename='misp.export.xml';
		}
		$final = "";
		$final .= '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . '<response>' . PHP_EOL;
		$validEvents = 0;
		if (!$eventid) $eventIdArray = $this->Event->fetchEventIds($user, $from, $to, $last, true);
		foreach ($eventIdArray as $currentEventId) {
			$result = $this->Event->fetchEvent($user, array('eventid' => $currentEventId, 'tags' => $tags, 'from' => $from, 'to' => $to, 'last' => $last));
			if (empty($result)) continue;
			$validEvents++;
			if ($withAttachment) {
				foreach ($result[0]['Attribute'] as $key => $attribute) {
					if ($this->Event->Attribute->typeIsAttachment($attribute['type'])) {
						$encodedFile = $this->Event->Attribute->base64EncodeAttachment($attribute);
						$result[0]['Attribute'][$key]['data'] = $encodedFile;
					}
				}
			}
			$result = $this->Whitelist->removeWhitelistedFromArray($result, false);
			$final .= $converter->convert($result[0]) . PHP_EOL;
		}
		if ($validEvents == 0) throw new NotFoundException('No events found that match the passed parameters.');
		$final .= '</response>' . PHP_EOL;
		$this->response->body($final);
		$this->response->type('xml');
		$this->response->download($final_filename);
		return $this->response;
	}

	// Grab an event or a list of events for the event view or any of the XML exports. The returned object includes an array of events (or an array that only includes a single event if an ID was given)
	// Included with the event are the attached attributes, shadow attributes, related events, related attribute information for the event view and the creating user's email address where appropriate
	private function __fetchEvent($eventid = false, $idList = false, $user = false, $tags = false, $from = false, $to = false) {
		// if we come from automation, we may not be logged in - instead we used an auth key in the URL.
		if (empty($user)) {
			$user = $this->Auth->user();
		}
		$results = $this->Event->fetchEvent($user, array('eventid' => $eventid, 'idList' => $idList, 'tags' => $tags, 'from' => $from, 'to' => $to));
		return $results;
	}

	public function nids($format = 'suricata', $key = 'download', $id = false, $continue = false, $tags = false, $from = false, $to = false, $last = false, $type = false, $enforceWarninglist = false, $includeAllTags = false, $eventid = false) {
		if ($this->request->is('post')) {
			if (empty($this->request->data)) {
				throw new BadRequestException('Either specify the search terms in the url, or POST a json or xml with the filter parameters. Valid filters: id (event ID), tags (list of tags), from (from date in YYYY-MM-DD format), to (to date in YYYY-MM-DD format), last (events with a published timestamp newer than - valid options are in time + unit format such as 6d or 2w, etc)');
			} else {
				$data = $this->request->data;
			}
			$paramArray = array('id', 'continue', 'tags', 'from', 'to', 'last', 'type', 'enforceWarninglist', 'eventid');
			if (!isset($data['request'])) {
				$data = array('request' => $data);
			}
			foreach ($paramArray as $p) {
				if (isset($data['request'][$p])) ${$p} = $data['request'][$p];
				else ${$p} = null;
			}
		}

		$simpleFalse = array('id', 'continue', 'tags', 'from', 'to', 'last', 'type', 'enforceWarninglist', 'includeAllTags', 'eventid');
		foreach ($simpleFalse as $sF) {
			if (!is_array(${$sF}) && (${$sF} === 'null' || ${$sF} == '0' || ${$sF} === false || strtolower(${$sF}) === 'false')) {
				${$sF} = false;
			}
		}
		if (!empty($eventid)) {
			$id = $eventid;
		}
		if ($from) $from = $this->Event->dateFieldCheck($from);
		if ($to) $to = $this->Event->dateFieldCheck($to);
		if ($tags) $tags = str_replace(';', ':', $tags);
		if ($last) $last = $this->Event->resolveTimeDelta($last);
		// backwards compatibility, swap key and format
		if ($format != 'snort' && $format != 'suricata') {
			$format = 'suricata'; // default format
		}
		$this->response->type('txt');	// set the content type
		$filename = 'misp.' . $format . '.rules';
		if ($id) $filename = 'misp.' . $format . '.event' . $id . '.rules';
		$this->header('Content-Disposition: download; filename="' . $filename . '"');
		$this->layout = 'text/default';
		if ($key != 'download') {
			// check if the key is valid -> search for users based on key
			$user = $this->checkAuthUser($key);
			if (!$user) {
				throw new UnauthorizedException('This authentication key is not authorized to be used for exports. Contact your administrator.');
			}
		} else {
			// check if there's a user logged in or not
			if (!$this->Auth->user('id')) {
				throw new UnauthorizedException('You have to be logged in to do that.');
			}
			$user = $this->Auth->user();
		}

		// display the full snort rulebase
		$this->loadModel('Attribute');
		$rules = $this->Attribute->nids($user, $format, $id, $continue, $tags, $from, $to, $last, $type, $enforceWarninglist, $includeAllTags);
		$this->set('rules', $rules);
		$this->render('/Events/nids');
	}

	public function hids($type, $key = 'download', $tags = false, $from = false, $to = false, $last = false, $enforceWarninglist = false) {
		$simpleFalse = array('tags', 'from', 'to', 'last', 'enforceWarninglist');
		if ($this->request->is('post')) {
			if (empty($this->request->data)) {
				throw new BadRequestException('Either specify the search terms in the url, or POST a json or xml with the filter parameters.');
			} else {
				$data = $this->request->data;
			}
			if (!isset($data['request'])) {
				$data = array('request' => $data);
			}
			foreach ($simpleFalse as $sF) {
				if (isset($data['request'][$sF])) {
					${$sF} = $data['request'][$sF];
				}
			}
		}
		foreach ($simpleFalse as $sF) {
			if (!is_array(${$sF}) && (${$sF} === 'null' || ${$sF} == '0' || ${$sF} === false || strtolower(${$sF}) === 'false')) {
				${$sF} = false;
			}
		}
		if (!in_array($type, array('md5', 'sha1', 'sha256'))) {
			throw new MethodNotAllowedException('Invalid hash type.');
		}
		if ($from) $from = $this->Event->dateFieldCheck($from);
		if ($to) $to = $this->Event->dateFieldCheck($to);
		if ($tags) $tags = str_replace(';', ':', $tags);
		if ($last) $last = $this->Event->resolveTimeDelta($last);
		$this->response->type('txt');	// set the content type
		$this->header('Content-Disposition: download; filename="misp.' . $type . '.rules"');
		$this->layout = 'text/default';
		if ($key != 'download') {
			// check if the key is valid -> search for users based on key
			$user = $this->checkAuthUser($key);
			if (!$user) {
				throw new UnauthorizedException('This authentication key is not authorized to be used for exports. Contact your administrator.');
			}
		} else {
			// check if there's a user logged in or not
			if (!$this->Auth->user('id')) {
				throw new UnauthorizedException('You have to be logged in to do that.');
			}
		}
		$this->loadModel('Attribute');
		$rules = $this->Attribute->hids($this->Auth->user(), $type, $tags, $from, $to, $last, false, $enforceWarninglist);
		return new CakeResponse(array('body'=> implode(PHP_EOL, $rules), 'status' => 200, 'type' => 'txt'));
	}

	// csv function
	// Usage: csv($key, $eventid)   - key can be a valid auth key or the string 'download'. Download requires the user to be logged in interactively and will generate a .csv file
	// $eventid can be one of 3 options: left empty it will get all the visible to_ids attributes,
	// $ignore is a flag that allows the export tool to ignore the ids flag. 0 = only IDS signatures, 1 = everything.
	public function csv($key, $eventid = false, $ignore = false, $tags = false, $category = false, $type = false, $includeContext = false, $from = false, $to = false, $last = false, $headerless = false, $enforceWarninglist = false, $value = false) {
		$paramArray = array('eventid', 'ignore', 'tags', 'category', 'type', 'includeContext', 'from', 'to', 'last', 'headerless', 'enforceWarninglist', 'value');
		if ($this->request->is('post')) {
			if (empty($this->request->data)) {
				return $this->RestResponse->throwException(400, 'Either specify the search terms in the url, or POST a json or xml with the filter parameters.', 'csv', true);
			} else {
				$data = $this->request->data;
			}
			if (!isset($data['request'])) {
				$data = array('request' => $data);
			}
			foreach ($paramArray as $p) {
				if (isset($data['request'][$p])) ${$p} = $data['request'][$p];
			}
		}
		foreach ($paramArray as $p) {
			if (!is_array(${$p}) && (${$p} === 'null' || ${$p} == '0' || ${$p} === false || strtolower(${$p}) === 'false')) {
				${$p} = false;
			}
		}
		$exportType = $eventid;
		if ($from) $from = $this->Event->dateFieldCheck($from);
		if ($to) $to = $this->Event->dateFieldCheck($to);
		if ($tags) $tags = str_replace(';', ':', $tags);
		if ($last) $last = $this->Event->resolveTimeDelta($last);
		$list = array();
		if ($key != 'download') {
			// check if the key is valid -> search for users based on key
			$user = $this->checkAuthUser($key);
			if (!$user) {
				return $this->RestResponse->throwException(401, 'This authentication key is not authorized to be used for exports. Contact your administrator.', 'csv', true);
			}
		} else {
			if (!$this->Auth->user('id')) {
				return $this->RestResponse->throwException(401, 'You have to be logged in to do that.', 'csv', true);
			}
			$user = $this->Auth->user();
		}
		// if it's a search, grab the attributeIDList from the session and get the IDs from it. Use those as the condition
		// We don't need to look out for permissions since that's filtered by the search itself
		// We just want all the attributes found by the search
		if ($eventid === 'search') {
			$ioc = $this->Session->read('paginate_conditions_ioc');
			$paginateConditions = $this->Session->read('paginate_conditions');
			$attributes = $this->Event->Attribute->find('all', array(
				'conditions' => $paginateConditions['conditions'],
				'contain' => $paginateConditions['contain'],
			));
			if ($ioc) {
				$this->loadModel('Whitelist');
				$attributes = $this->Whitelist->removeWhitelistedFromArray($attributes, true);
			}
			$list = array();
			foreach ($attributes as $attribute) {
				$list[] = $attribute['Attribute']['id'];
			}
			$events = array($eventid);
		} else if ($eventid === false) {
			$events = $this->Event->fetchEventIds($this->Auth->user(), $from, $to, $last, true);
			if (empty($events)) $events = array(0 => -1);
		} else {
			$events = array($eventid);
		}
		$final = array();
		$this->loadModel('Whitelist');
		if ($tags) {
			$args = $this->Event->Attribute->dissectArgs($tags);
			$tagArray = $this->Event->EventTag->Tag->fetchEventTagIds($args[0], $args[1]);
			if (!empty($tagArray[0])) $events = array_intersect($events, $tagArray[0]);
			if (!empty($tagArray[1])) {
				foreach ($events as $k => $eventid) {
					if (in_array($eventid, $tagArray[1])) unset($events[$k]);
				}
			}
		}
		$requested_attributes = array('uuid', 'event_id', 'category', 'type',
								'value', 'comment', 'to_ids', 'timestamp');
		$requested_obj_attributes = array('uuid', 'name', 'meta-category');
		if (isset($this->params['url']['attributes'])) {
			if (!isset($this->params['url']['obj_attributes'])) $requested_obj_attributes = array();
			$requested_attributes = explode(',', $this->params['url']['attributes']);
		}
		if (isset($this->params['url']['obj_attributes'])) {
			$requested_obj_attributes = explode(',', $this->params['url']['obj_attributes']);
		}
		if (isset($data['request']['attributes'])) {
			if (!isset($data['request']['obj_attributes'])) $requested_obj_attributes = array();
			$requested_attributes = $data['request']['attributes'];
		}
		if (isset($data['request']['obj_attributes'])) $requested_obj_attributes = $data['request']['obj_attributes'];
		if (isset($events)) {
			$events = array_chunk($events, 100);
			foreach ($events as $k => $eventid) {
				$attributes = $this->Event->csv($user, $eventid, $ignore, $list, false, $category, $type, $includeContext, false, false, false, $enforceWarninglist, $value);
				$attributes = $this->Whitelist->removeWhitelistedFromArray($attributes, true);
				foreach ($attributes as $attribute) {
					$line1 = '';
					$line2 = '';
					foreach ($requested_attributes as $requested_attribute) {
						$line1 .= $attribute['Attribute'][$requested_attribute] . ',';
					}
					$line1 = rtrim($line1, ",");
					foreach ($requested_obj_attributes as $requested_obj_attribute) {
						$line2 .= $attribute['Object'][$requested_obj_attribute] . ',';
					}
					$line2 = rtrim($line2, ",");
					$line = $line1 . ',' . $line2;
					$line = rtrim($line, ",");
					if ($includeContext) {
						foreach ($this->Event->csv_event_context_fields_to_fetch as $header => $field) {
							if ($field['object']) $line .= ',' . $attribute['Event'][$field['object']][$field['var']];
							else $line .= ',' . $attribute['Event'][$field['var']];
						}
					}
					$final[] = $line;
				}
			}
		}
		$this->response->type('csv');	// set the content type
		if (!$exportType) {
			$filename = "misp.all_attributes.csv";
		} else if ($exportType === 'search') {
			$filename = "misp.search_result.csv";
		} else {
			$filename = "misp.event_" . $exportType . ".csv";
		}
		$this->layout = 'text/default';
		if (!empty($requested_obj_attributes)) {
			array_walk($requested_obj_attributes, function(&$value, $key) { $value = 'object-'.$value; } );
		}
		$headers = array_merge($requested_attributes, $requested_obj_attributes);
		if ($includeContext) $headers = array_merge($headers, array_keys($this->Event->csv_event_context_fields_to_fetch));
		foreach ($headers as $k => $v) {
			$headers[$k] = str_replace('-', '_', $v);
			if ($v == 'timestamp') $headers[$k] = 'date';
		}
		$headers = implode(',', $headers);
		$final = array_merge(array($headers), $final);
		$final = implode(PHP_EOL, $final);
		$final .= PHP_EOL;
		return $this->RestResponse->viewData($final, 'csv', false, true, $filename);
	}

	public function _addGfiZip($id) {
		if (!empty($this->data) && $this->data['Event']['submittedgfi']['size'] > 0 &&
				is_uploaded_file($this->data['Event']['submittedgfi']['tmp_name'])) {
			App::uses('FileAccessTool', 'Tools');
			$fileAccessTool = new FileAccessTool();
			$zipData = $fileAccessTool->readFromFile($this->data['Event']['submittedgfi']['tmp_name'], $this->data['Event']['submittedgfi']['size']);

			// write
			$attachments_dir = Configure::read('MISP.attachments_dir');
			if (empty($attachments_dir)) {
				$this->loadModel('Server');
				$attachments_dir = $this->Server->getDefaultAttachments_dir();
			}
			$rootDir = $attachments_dir . DS . "GFI" . DS . $id . DS;
			App::uses('Folder', 'Utility');
			$dir = new Folder($rootDir, true);
			if (!$this->Event->checkFilename($this->data['Event']['submittedgfi']['name'])) {
				throw new Exception ('Filename not allowed.');
			}
			$zipFile = new File($rootDir . $this->data['Event']['submittedgfi']['name']);
			$result = $zipFile->write($zipData);
			if (!$result) $this->Session->setFlash(__('Problem with writing the zip file. Please report to administrator.'));

			// extract zip
			$execRetval = '';
			$execOutput = array();
			exec("unzip " . $zipFile->path . ' -d ' . $rootDir, $execOutput, $execRetval);
			if ($execRetval != 0) {	// not EXIT_SUCCESS
				throw new Exception('An error has occured while attempting to unzip the GFI sandbox .zip file. We apologise for the inconvenience.');
			}

			// open the xml
			$xmlFileName = 'analysis.xml';
			$xmlFilePath = $rootDir . DS . 'Analysis' . DS . $xmlFileName;
			$xmlFileData = $fileAccessTool->readFromFile($xmlFilePath);

			// read XML
			$this->_readGfiXML($xmlFileData, $id);
		}
	}

	public function _addIOCFile($id) {
		if (!empty($this->data) && $this->data['Event']['submittedioc']['size'] > 0 &&
				is_uploaded_file($this->data['Event']['submittedioc']['tmp_name'])) {
			if (!$this->Event->checkFilename($this->data['Event']['submittedioc']['name'])) {
				throw new Exception ('Filename not allowed.');
			}

			App::uses('FileAccessTool', 'Tools');
			$fileAccessTool = new FileAccessTool();
			$iocData = $fileAccessTool->readFromFile($this->data['Event']['submittedioc']['tmp_name'], $this->data['Event']['submittedioc']['size']);

			// write
			$attachments_dir = Configure::read('MISP.attachments_dir');
			if (empty($attachments_dir)) {
				$this->loadModel('Server');
				$attachments_dir = $this->Server->getDefaultAttachments_dir();
			}
			$rootDir = $attachments_dir . DS . $id . DS;
			App::uses('Folder', 'Utility');
			$dir = new Folder($rootDir . 'ioc', true);
			$destPath = $rootDir . 'ioc';
			App::uses('File', 'Utility');
			$iocFile = new File($destPath . DS . $this->data['Event']['submittedioc']['name']);
			$result = $iocFile->write($iocData);
			if (!$result) $this->Session->setFlash(__('Problem with writing the ioc file. Please report to administrator.'));

			// open the xml
			$xmlFilePath = $destPath . DS . $this->data['Event']['submittedioc']['name'];
			$xmlFileData = $fileAccessTool->readFromFile($xmlFilePath, $this->data['Event']['submittedioc']['size']);

			// Load event and populate the event data
			$this->Event->id = $id;
			$this->Event->recursive = -1;
			if (!$this->Event->exists()) {
				throw new NotFoundException(__('Invalid event'));
			}
			$this->Event->read(null, $id);
			$saveEvent['Event'] = $this->Event->data['Event'];
			$saveEvent['Event']['published'] = false;
			$dist = '5';
			if (Configure::read('MISP.default_attribute_distribution') != null) {
				if (Configure::read('MISP.default_attribute_distribution') === 'event') {
					$dist = '5';
				} else {
					$dist = '';
					$dist .= Configure::read('MISP.default_attribute_distribution');
				}
			}
			// read XML
			$event = $this->IOCImport->readXML($xmlFileData, $id, $dist, $this->data['Event']['submittedioc']['name']);

			// make some changes to have $saveEvent in the format that is needed to save the event together with its attributes
			$fails = $event['Fails'];
			$saveEvent['Attribute'] = $event['Attribute'];
			// we've already stored these elsewhere, unset them so we can extract the event related data
			unset($event['Attribute']);
			unset($event['Fails']);

			// add the original openIOC file as an attachment
			$saveEvent['Attribute'][] = array(
				'category' => 'External analysis',
				'uuid' =>  CakeText::uuid(),
				'type' => 'attachment',
				'value' => $this->data['Event']['submittedioc']['name'],
				'to_ids' => false,
				'distribution' => $dist,
				'data' => base64_encode($xmlFileData),
				'comment' => 'OpenIOC import source file'
			);

			// LATER we might want to let an ioc create the event data automatically in a later version
			// save the event related data into $saveEvent['Event']
			//$saveEvent['Event'] = $event;
			//$saveEvent['Event']['id'] = $id;

			$fieldList = array(
					'Event' => array('published', 'timestamp'),
					'Attribute' => array('event_id', 'category', 'type', 'value', 'value1', 'value2', 'to_ids', 'uuid', 'distribution', 'timestamp', 'comment')
			);
			// Save it all
			$saveResult = $this->Event->saveAssociated($saveEvent, array('validate' => true, 'fieldList' => $fieldList));
			// set stuff for the view and render the showIOCResults view.
			$this->set('attributes', $saveEvent['Attribute']);
			if (isset($fails)) {
				$this->set('fails', $fails);
			}
			$this->set('eventId', $id);
			$this->set('graph', $event['Graph']);
			$this->set('saveEvent', $saveEvent);
			$this->render('showIOCResults');
		}
	}

	public function _addMISPExportFile($ext, $take_ownership = false, $publish = false) {
		App::uses('FileAccessTool', 'Tools');
		$data = (new FileAccessTool())->readFromFile($this->data['Event']['submittedfile']['tmp_name'], $this->data['Event']['submittedfile']['size']);

		if ($ext == 'xml') {
			App::uses('Xml', 'Utility');
			$dataArray = Xml::toArray(Xml::build($data));
		} else {
			$dataArray = json_decode($data, true);
			if (isset($dataArray['response'][0])) {
				foreach ($dataArray['response'] as $k => $temp) {
					$dataArray['Event'][] = $temp['Event'];
					unset($dataArray['response'][$k]);
				}
			}
		}
		// In case we receive an event that is not encapsulated in a response. This should never happen (unless it's a copy+paste fail),
		// but just in case, let's clean it up anyway.
		if (isset($dataArray['Event'])) {
			$dataArray['response']['Event'] = $dataArray['Event'];
			unset($dataArray['Event']);
		}
		if (!isset($dataArray['response']) || !isset($dataArray['response']['Event'])) {
			throw new Exception('This is not a valid MISP XML file.');
		}
		$dataArray = $this->Event->updateXMLArray($dataArray);
		$results = array();
		$validationIssues = array();
		if (isset($dataArray['response']['Event'][0])) {
			foreach ($dataArray['response']['Event'] as $k => $event) {
				$result = array('info' => $event['info']);
				if ($take_ownership) {
					$event['orgc_id'] = $this->Auth->user('org_id');
					unset($event['Orgc']);
				}
				$event = array('Event' => $event);
				$created_id = 0;
				$event['Event']['locked'] = 1;
				$event['Event']['published'] = $publish;
				$result['result'] = $this->Event->_add($event, true, $this->Auth->user(), '', null, false, null, $created_id, $validationIssues);
				$result['id'] = $created_id;
				$result['validationIssues'] = $validationIssues;
				$results[] = $result;
			}
		} else {
			$temp['Event'] = $dataArray['response']['Event'];
			if ($take_ownership)  {
				$temp['Event']['orgc_id'] = $this->Auth->user('org_id');
				unset($temp['Event']['Orgc']);
			}
			$created_id = 0;
			$temp['Event']['locked'] = 1;
			$temp['Event']['published'] = $publish;
			$result = $this->Event->_add($temp, true, $this->Auth->user(), '', null, false, null, $created_id, $validationIssues);
			$results = array(0 => array('info' => $temp['Event']['info'], 'result' => $result, 'id' => $created_id, 'validationIssues' => $validationIssues));
		}
		return $results;
	}

	public function _readGfiXML($data, $id) {
		$this->loadModel('Attribute');
		$this->Event->recursive = -1;
		$this->Event->read(array('id', 'uuid', 'distribution'), $id);

		// import XML class
		App::uses('Xml', 'Utility');
		// now parse it
		try {
			$parsedXml = Xml::build($data, array('return' => 'simplexml'));
		} catch (Exception $e) {
			$this->Session->setFlash('Invalid GFI archive.');
			$this->redirect(array('controller' => 'events', 'action' => 'view', $id));
		}

		// xpath..
		if (Configure::read('MISP.default_attribute_distribution') != null) {
			if (Configure::read('MISP.default_attribute_distribution') === 'event') {
				$dist = $this->Event->data['Event']['distribution'];
			} else {
				$dist = '';
				$dist .= Configure::read('MISP.default_attribute_distribution');
			}
		} else {
			throw new Exception('Couldn\'t read "MISP.default_attribute_distribution".');
		}

		// Payload delivery -- malware-sample
		$realFileName = '';
		$results = $parsedXml->xpath('/analysis');
		foreach ($results as $result) {
			foreach ($result[0]->attributes() as $key => $val) {
				if ((string)$key == 'filename') $realFileName = (string)$val;
			}
		}
		$attachments_dir = Configure::read('MISP.attachments_dir');
		if (empty($attachments_dir)) {
			$this->loadModel('Server');
			$attachments_dir = $this->Server->getDefaultAttachments_dir();
		}
		$rootDir = $attachments_dir . DS . $id . DS;
		$malware = $rootDir . DS . 'sample';
		$this->Event->Attribute->uploadAttachment($malware,	$realFileName,	true, $id, null, '', $this->Event->data['Event']['uuid'] . '-sample', $dist, true);

		// Network activity -- .pcap
		$realFileName = 'analysis.pcap';
		$rootDir = $attachments_dir . DS . $id . DS;
		$malware = $rootDir . DS . 'Analysis' . DS . 'analysis.pcap';
		$this->Event->Attribute->uploadAttachment($malware,	$realFileName,	false, $id, 'Network activity', '', $this->Event->data['Event']['uuid'] . '-analysis.pcap', $dist, true);

		// Artifacts dropped -- filename|md5
		$files = array();
		// TODO what about stored_modified_file ??
		$results = $parsedXml->xpath('/analysis/processes/process/stored_files/stored_created_file');
		foreach ($results as $result) {
			$arrayItemKey = '';
			$arrayItemValue = '';
			$arrayItemSize = 0;
			foreach ($result[0]->attributes() as $key => $val) {
				if ($key == 'filename') $arrayItemKey = (string)$val;
				if ($key == 'md5') $arrayItemValue = (string)$val;
				if ($key == 'filesize') $arrayItemSize = $val;
			}
			if ($arrayItemSize > 0) {
				$files[] = array('key' => $arrayItemKey, 'val' => $arrayItemValue);
			}
		}
		// write content..
		$actualFileNameArray = array();
		foreach ($files as $file) {
			$keyName = $file['key'];

			// the actual files..
			// seek $val in dirs and add..
			$ext = substr($file['key'], strrpos($file['key'], '.'));
			$actualFileName = $file['val'] . $ext;
			$actualFileNameBase = str_replace('\\', '/', $file['key']);
			$actualFileNameArray[] = basename($actualFileNameBase);
			$tempExplode = explode('\\', $file['key']);
			$realFileName = end($tempExplode);
			// have the filename, now look at parents parent for the process number
			$express = "/analysis/processes/process/stored_files/stored_created_file[@md5='" . $file['val'] . "']/../..";
			$results = $parsedXml->xpath($express);
			foreach ($results as $result) {
				foreach ($result[0]->attributes() as $key => $val) {
					if ((string)$key == 'index') $index = (string)$val;
				}
			}
			if (!isset($index) || !is_numeric($index)) {
				throw new Exception('The GFI sandbox xml file seems to be malformed, at least one process with stored_files hasn\'t got a valid numeric index attribute.');
			}
			$actualFile = $rootDir . DS . 'Analysis' . DS . 'proc_' . $index . DS . 'modified_files' . DS . $actualFileName;
			$extraPath = 'Analysis' . DS . 'proc_' . $index . DS . 'modified_files' . DS;
			$file = new File($actualFile);
			if ($file->exists()) { // TODO put in array for test later
				$this->Event->Attribute->uploadAttachment($actualFile, $realFileName, true, $id, null, $extraPath, $keyName, $dist, true); // TODO was false
			} else {
			}
		}

		// Network activity -- ip-dst
		$ips = array();
		$hostnames = array();
		$results = $parsedXml->xpath('/analysis/processes/process/networkpacket_section/connect_to_computer');
		foreach ($results as $result) {
			foreach ($result[0]->attributes() as $key => $val) {
				if ($key == 'remote_ip') $ips[] = (string)$val;
				if ($key == 'remote_hostname') $hostnames[] = (string)$val;
			}
		}
		// write content..
		// ip-s
		foreach ($ips as $ip) {
			// add attribute..
			$this->Attribute->create();
			$this->Attribute->save(array(
					'event_id' => $id,
					'category' => 'Network activity',
					'type' => 'ip-dst',
					'value' => $ip,
					'to_ids' => false,
					'distribution' => $dist,
					'comment' => 'GFI import',
					));
		}
		foreach ($hostnames as $hostname) {
			// add attribute..
			$this->Attribute->create();
			$this->Attribute->save(array(
					'event_id' => $id,
					'category' => 'Network activity',
					'type' => 'hostname',
					'value' => $hostname,
					'to_ids' => false,
					'distribution' => $dist,
					'comment' => 'GFI import',
			));
		}
		// Persistence mechanism -- regkey|value
		$regs = array();
		$results = $parsedXml->xpath('/analysis/processes/process/registry_section/set_value');
		foreach ($results as $result) {
			$arrayItemKey = '';
			$arrayItemValue = '';
			foreach ($result[0]->attributes() as $key => $val) {
				if ($key == 'key_name') $arrayItemKey = (string)$val;
				if ($key == 'data') $arrayItemValue = (string)$val;
			}
			$regs[$arrayItemKey] = str_replace('(UNICODE_0x00000000)', '', $arrayItemValue);
		}

		// write content..
		foreach ($regs as $key => $val) {
			// add attribute..
			$this->Attribute->create();

			if ($this->__strposarray($val,$actualFileNameArray)) {
				$this->Attribute->save(array(
					'event_id' => $id,
					'comment' => 'GFI import',
					'category' => 'Persistence mechanism', // 'Persistence mechanism'
					'type' => 'regkey|value',
					'value' => $key . '|' . $val,
					'distribution' => $dist,
					'to_ids' => false
				));
			}
		}
	}

	private function __strposarray($string, $array) {
		$toReturn = false;
		foreach ($array as $item) {
			if (strpos($string,$item)) {
				$toReturn = true;
			}
		}
		return $toReturn;
	}

	public function downloadSearchResult() {
		$ioc = $this->Session->read('paginate_conditions_ioc');
		$paginateConditions = $this->Session->read('paginate_conditions');
		$attributes = $this->Event->Attribute->fetchAttributes($this->Auth->user(), array(
			'conditions' => $paginateConditions['conditions'],
			'contain' => $paginateConditions['contain'],
		));
		if ($ioc) {
			$this->loadModel('Whitelist');
			$attributes = $this->Whitelist->removeWhitelistedFromArray($attributes, true);
		}
		$idList = array();
		foreach ($attributes as $attribute) {
			if (!in_array($attribute['Attribute']['event_id'], $idList)) {
				$idList[] = $attribute['Attribute']['event_id'];
			}
		}
		$results = $this->__fetchEvent(null, $idList);
		$this->set('results', $results);
		if ($this->response->type() === 'application/json') $type = 'json';
		else $type = 'xml';
		App::uses(strtoupper($type) . 'ConverterTool', 'Tools');
		$tool = strtoupper($type) . 'ConverterTool';
		$converter = new $tool();
		$body = $converter->eventCollection2Format($results);
		$body = $converter->frameCollection($body, $this->mispVersion);
		$this->response->body($body);
		$this->response->download('misp.search.results.' . $type);
		return $this->response;
	}

	// Use the REST interface to search for attributes or events. Usage:
	// MISP-base-url/events/restSearch/[api-key]/[value]/[type]/[category]/[orgc]
	// value, type, category, orgc are optional
	// target can be either "event" or "attribute"
	// the last 4 fields accept the following operators:
	// && - you can use && between two search values to put a logical OR between them. for value, 1.1.1.1&&2.2.2.2 would find attributes with the value being either of the two.
	// ! - you can negate a search term. For example: google.com&&!mail would search for all attributes with value google.com but not ones that include mail. www.google.com would get returned, mail.google.com wouldn't.
	public function restSearch($key = 'download', $value = false, $type = false, $category = false, $org = false, $tags = false, $searchall = false, $from = false, $to = false, $last = false, $eventid = false, $withAttachments = false, $metadata = false, $uuid = false, $publish_timestamp = false, $timestamp = false, $published = false, $enforceWarninglist = false, $sgReferenceOnly = false) {
		if ($key != null && strlen($key) == 40) {
			if (!$this->checkAuthUser($key)) {
				throw new UnauthorizedException('This authentication key is not authorized to be used for exports. Contact your administrator.');
			}
		} else {
			$key = strtolower($key);
			if (!$this->Auth->user()) throw new UnauthorizedException('You are not authorized. Please send the Authorization header with your auth key along with an Accept header for application/xml.');
		}
		if (!is_array($value) && $value !== false) $value = str_replace('|', '/', $value);
		// request handler for POSTed queries. If the request is a post, the parameters (apart from the key) will be ignored and replaced by the terms defined in the posted json or xml object.
		// The correct format for both is a "request" root element, as shown by the examples below:
		// For Json: {"request":{"value": "7.7.7.7&&1.1.1.1","type":"ip-src"}}
		// For XML: <request><value>7.7.7.7&amp;&amp;1.1.1.1</value><type>ip-src</type></request>
		// the response type is used to determine the parsing method (xml/json)
		if ($this->request->is('post')) {
			if ($this->response->type() === 'application/json') {
				$data = $this->request->input('json_decode', true);
			} else if ($this->response->type() === 'application/xml') {
				$data = $this->request->data;
			} else {
				throw new BadRequestException('Either specify the search terms in the url, or POST a json array / xml (with the root element being "request" and specify the correct headers based on content type.');
			}
			if (!isset($data['request'])) {
				$data['request'] = $data;
			}
			$paramArray = array('value', 'type', 'category', 'org', 'tag', 'tags', 'searchall', 'from', 'to', 'last', 'eventid', 'withAttachments', 'metadata', 'uuid', 'published', 'publish_timestamp', 'timestamp', 'enforceWarninglist', 'sgReferenceOnly');
			foreach ($paramArray as $p) {
				if (isset($data['request'][$p])) {
					${$p} = $data['request'][$p];
				} else {
					${$p} = null;
				}
			}
		}
		$simpleFalse = array('value' , 'type', 'category', 'org', 'tags', 'searchall', 'from', 'to', 'last', 'eventid', 'withAttachments', 'uuid', 'publish_timestamp', 'timestamp', 'enforceWarninglist', 'sgReferenceOnly');
		foreach ($simpleFalse as $sF) {
			if (!is_array(${$sF}) && (${$sF} === 'null' || ${$sF} == '0' || ${$sF} === false || strtolower(${$sF}) === 'false')) {
				${$sF} = false;
			}
		}
		if ($from) $from = $this->Event->dateFieldCheck($from);
		if ($to) $to = $this->Event->dateFieldCheck($to);
		if (!empty($tag) && !$tags) {
			$tags = $tag;
		}
		if ($tags) $tags = str_replace(';', ':', $tags);
		if ($last) $last = $this->Event->resolveTimeDelta($last);
		if ($searchall === 'true') $searchall = "1";
		$conditions['AND'] = array();
		$subcondition = array();
		$this->loadModel('Attribute');
		// add the values as specified in the 2nd parameter to the conditions
		if (isset($searchall) && ($searchall == 1 || $searchall === true || $searchall == 'true')) {
			$eventIds = $this->__quickFilter($value);
		} else {
			$parameters = array('value', 'type', 'category', 'org', 'uuid', 'eventid');
			$attributeLevelFilters = array('value', 'type', 'category', 'uuid');
			$preFilterLevel = 'event';
			foreach ($parameters as $k => $param) {
				if (${$parameters[$k]} !== null && ${$parameters[$k]} !== false) {
					if (in_array($param, $attributeLevelFilters)) {
						$preFilterLevel = 'attribute';
					}
					if ($param == 'eventid') {
						$restrictScopeToEvents = true;
					}
					$conditions = $this->Event->setSimpleConditions($parameters[$k], ${$parameters[$k]}, $conditions, !empty($restrictScopeToEvents));
				}
			}
			// If we sent any tags along, load the associated tag names for each attribute
			if ($tags) $conditions = $this->Event->Attribute->setTagConditions($tags, $conditions);
			$blockedAttributeTags = array();
			if (!empty($tags)) {
				if (!is_array($tags)) {
					$tags = explode('&&', $tags);
				}
				foreach ($tags as $tag) {
					if ($tag[0] == '!') {
						$blockedAttributeTags[] = ltrim($tag, '!');
					}
				}
				$preFilterLevel = 'attribute';
			}
			if ($from) $conditions['AND'][] = array('Event.date >=' => $from);
			if ($to) $conditions['AND'][] = array('Event.date <=' => $to);
			if ($publish_timestamp) $conditions = $this->Event->Attribute->setPublishTimestampConditions($publish_timestamp, $conditions);
			if ($timestamp) $conditions = $this->Event->Attribute->setTimestampConditions($timestamp, $conditions);
			if ($last) $conditions['AND'][] = array('Event.publish_timestamp >=' => $last);
			if ($published !== null && $published !== false) $conditions['AND'][] = array('Event.published' => $published);
			if ($preFilterLevel == 'event') {
				$params = array(
					'conditions' => $conditions
				);
				$eventIds = $this->Event->fetchSimpleEventIds($this->Auth->user(), $params);
			} else {
				$params = array(
						'conditions' => $conditions,
						'fields' => array('DISTINCT(Attribute.event_id)'),
						'contain' => array(),
						'recursive' => -1,
						'list' => true,
						'event_ids' => true,
						'flatten' => 1
				);
				$attributes = $this->Event->Attribute->fetchAttributes($this->Auth->user(), $params);
				$eventIds = array();
				if (!empty($attributes)) {
					$eventIds = array_values($attributes);
				}
				if (is_array($eventid)) {
					foreach ($eventid as $temp_id) {
						if (!in_array($temp_id, $eventIds)) $eventIds[] = $temp_id;
					}
				} else {
					if ($eventid && !in_array($eventid, $eventIds)) $eventIds[] = $eventid;
				}
				unset($attributes);
			}
		}
		$this->loadModel('Whitelist');
		$responseType = 'xml';
		$converters = array(
			'xml' => 'XMLConverterTool',
			'json' => 'JSONConverterTool',
			'openioc' => 'IOCExportTool'
		);
		if (in_array($key, array('json', 'xml', 'openioc'))) {
			$responseType = $key;
		} else if (((isset($this->request->params['ext']) && $this->request->params['ext'] == 'xml')) || $this->response->type() == 'application/xml') {
			$responseType = 'xml';
		} else {
			$responseType = 'json';
		}
		App::uses($converters[$responseType], 'Tools');
		$converter = new $converters[$responseType]();
		$final = $converter->generateTop($this->Auth->user());
		$eventCount = count($eventIds);
		$i = 0;
		foreach ($eventIds as $k => $currentEventId) {
			$i++;
			$result = $this->Event->fetchEvent(
				$this->Auth->user(),
				array(
					'blockedAttributeTags' => $blockedAttributeTags,
					'eventid' => $currentEventId,
					'includeAttachments' => $withAttachments,
					'metadata' => $metadata,
					'enforceWarninglist' => $enforceWarninglist,
					'sgReferenceOnly' => $sgReferenceOnly
				),
				true
			);
			if (!empty($result)) {
				$result = $this->Whitelist->removeWhitelistedFromArray($result, false);
				$final .= $converter->convert($result[0]);
				if ($i < $eventCount) {
					$final .= ',' . PHP_EOL;
				}
			}
		}
		$final .= $converter->generateBottom($responseType, $final);
		$extension = $responseType;
		if ($key == 'openioc') {
			$extension = '.ioc';
		}
		if (isset($eventid) && $eventid) {
			if (is_array($eventid)) $eventid = 'list';
			$final_filename="misp.event." . $eventid . "." . $result[0]['Event']['uuid'] . '.' . $extension;
		} else {
			$final_filename="misp.search.events.results." . $extension;
		};
		return $this->RestResponse->viewData($final, $this->response->type(), false, true, $final_filename);
	}

	public function downloadOpenIOCEvent($key, $eventid, $enforceWarninglist = false) {
		// return a downloadable text file called misp.openIOC.<eventId>.ioc for individual events
		// TODO implement mass download of all events - maybe in a zip file?
		$this->response->type('text');	// set the content type
		if ($eventid == null) {
			throw new Exception('Not yet implemented');
		}
		$this->layout = 'text/default';

		if ($key != 'download'){
			$user = $this->checkAuthUser($key);
			if (!$user) {
				throw new UnauthorizedException('This authentication key is not authorized to be used for exports. Contact your administrator.');
			}
		} else {
			if (!$this->Auth->user('id')) {
				throw new UnauthorizedException('You have to be logged in to do that.');
			}
		}

		// get the event if it exists and load it together with its attributes
		$this->Event->id = $eventid;
		if (!$this->Event->exists()) {
			throw new NotFoundException(__('Invalid event or not authorised.'));
		}
		$event = $this->Event->fetchEvent($this->Auth->user(), $options = array('eventid' => $eventid, 'to_ids' => 1, 'enforceWarninglist' => $enforceWarninglist));
		if (empty($event)) throw new NotFoundException('Invalid event or not authorised.');
		$this->loadModel('Whitelist');
		$temp = $this->Whitelist->removeWhitelistedFromArray(array($event[0]), false);
		$event = $temp[0];

		// send the event and the vars needed to check authorisation to the Component
		App::uses('IOCExportTool', 'Tools');
		$this->IOCExport = new IOCExportTool();
		$final = $this->IOCExport->buildAll($this->Auth->user(), $event);
		$this->response->type('xml');
		$this->autoRender = false;
		$this->response->body($final);
		$this->response->download('misp.openIOC' . $eventid . '.ioc');
		return $this->response;
	}

	public function create_dummy_event() {
		if (!$this->_isSiteAdmin() || !$this->request->is('post')) throw new MethodNotAllowedException('You don\'t have the privileges to access this.');
		$data['Event']['info'] = 'Test event showing every category-type combination';
		$data['Event']['date'] = '2013-10-09';
		$data['Event']['threat_level_id'] = 4; //'Undefined'
		$data['Event']['analysis'] = '0';
		$data['Event']['distribution'] = '0';

		$defaultValues = array(
				'md5' => '098f6bcd4621d373cade4e832627b4f6',
				'sha1' => 'a7645200866fd00bde529733ceac8506ab1f5518',
				'sha256' => '0f58957831a9cf0b768451ee6b236555f519c04f0da5a5ea87538fd0990b29d1',
				'filename' => 'test.exe',
				'filename|md5' => 'test.exe|8886be8e4e862189a68d27e8fc7a6144',
				'filename|sha1' => 'test.exe|a7645200866fd00bde529733ceac8506ab1f5518',
				'filename|sha256' => 'test.exe|0f58957831a9cf0b768451ee6b236555f519c04f0da5a5ea87538fd0990b29d1',
				'ip-src' => '1.1.1.1',
				'ip-dst' => '2.2.2.2',
				'hostname' => 'www.futuremark.com',
				'domain' => 'evildomain.org',
				'email-src' => 'bla@bla.com',
				'email-dst' => 'hmm@hmm.com',
				'email-subject' => 'Some made-up email subject',
				'email-attachment' => 'filename.exe',
				'url' => 'http://www.evilsite.com/test',
				'http-method' => 'POST',
				'user-agent' => 'Microsoft Internet Explorer',
				'regkey' => 'HKLM\Software\Microsoft\Windows\CurrentVersion\Run\fishy',
				'regkey|value' => 'HKLM\Software\Microsoft\Windows\CurrentVersion\Run\fishy|%ProgramFiles%\Malicios\malware.exe',
				'AS' => '45566',
				'snort' => 'alert ip 1.1.1.1 any -> $HOME_NET any (msg: "MISP e1 Incoming From IP: 1.1.1.1"; classtype:trojan-activity; sid:21; rev:1; priority:1; reference:url,http://localhost:8888/events/view/1;)',
				'pattern-in-file' => 'Somestringinfile',
				'pattern-in-traffic' => 'Somestringintraffic',
				'pattern-in-memory' => 'Somestringinmemory',
				'yara' => 'rule silent_banker : banker{meta:description = "This is just an example" thread_level = 3 in_the_wild = true strings: $a = {6A 40 68 00 30 00 00 6A 14 8D 91} $b = {8D 4D B0 2B C1 83 C0 27 99 6A 4E 59 F7 F9} $c = "UVODFRYSIHLNWPEJXQZAKCBGMT" condition:}',
				'vulnerability' => 'CVE-2011-0001',
				'attachment' => 'file.txt',
				'malware-sample' => 'test.exe|8886be8e4e862189a68d27e8fc7a6144',
				'link' => 'http://www.somesite.com/',
				'comment' => 'Comment',
				'text' => 'Any text',
				'other' => 'Could be anything',
				'named pipe' => '\\.\pipe\PipeName',
				'mutex' => 'mutexstring',
				'target-user' => 'user1',
				'target-email' => 'someone@something.com',
				'target-machine' => 'machinename',
				'target-org' => 'EA games',
				'target-location' => 'Hell',
				'target-external' => 'some target'
		);
		$this->loadModel('Attribute');
		foreach ($this->Attribute->categoryDefinitions as $category => $v) {
			foreach ($v['types'] as $k => $type) {
				$data['Attribute'][] = array(
					'category' => $category,
					'type' => $type,
					'value' => $defaultValues[$type],
					'to_ids' => '0',
					'distribution' => '0',
				);
			}
		}
		$this->Event->_add($data, false, $this->Auth->user());
	}

	// for load testing, it's slow, execution time is set at 1 hour maximum
	public function create_massive_dummy_events() {
		if (!$this->_isSiteAdmin() || !$this->request->is('post')) throw new MethodNotAllowedException('You don\'t have the privileges to access this.');
		ini_set('max_execution_time', 3600);
		$this->Event->Behaviors->unload('SysLogLogable.SysLogLogable');
		$date = new DateTime();
		$ts =  $date->getTimestamp();
		$default = array('Event' => array(
			'info' => 'A junk event for load testing',
			'date' => '2014-09-01',
			'threat_level_id' => 4,
			'distribution' => 0,
			'analysis' => 0,
			'org_id' => $this->Auth->user('org_id'),
			'orgc_id' => $this->Auth->user('org_id'),
			'timestamp' => $ts,
			'uuid' => CakeText::uuid(),
			'user_id' => $this->Auth->user('id'),
		));
		$default['Event']['info'] = 'A junk event for load testing';
		$default['Event']['date'] = '2013-10-09';
		$default['Event']['threat_level_id'] = 4; //'Undefined'
		$default['Event']['analysis'] = '0';
		$default['Event']['distribution'] = '0';
		for ($i = 0; $i < 50; $i++) {
			$data = $default;
			for ($j = 0; $j < 3000; $j++) {
				$value = mt_rand();
				$data['Attribute'][] = array(
						'category' => 'Other',
						'type' => 'text',
						'value' => $value,
						'to_ids' => '0',
						'distribution' => '0',
						'value1' => $value,
						'value2' => '',
						'comment' => '',
						'uuid' => CakeText::uuid(),
						'timestamp' => $ts,
				);
			}
			$this->Event->create();
			$this->Event->saveAssociated($data, array('validate' => false));
		}
	}

	public function proposalEventIndex() {
		$this->loadModel('ShadowAttribute');
		$this->ShadowAttribute->recursive = -1;
		$conditions = array('ShadowAttribute.deleted' => 0);
		if (!$this->_isSiteAdmin()) $conditions[] = array('ShadowAttribute.event_org_id' => $this->Auth->user('org_id'));
		$result = $this->ShadowAttribute->find('all', array(
				'fields' => array('event_id'),
				'group' => array('event_id', 'id'),
				'conditions' => $conditions
		));
		$this->Event->recursive = -1;
		$conditions = array();
		foreach ($result as $eventId) {
				$conditions['OR'][] = array('Event.id =' => $eventId['ShadowAttribute']['event_id']);
		}
		if (empty($result)) {
			$conditions['OR'][] = array('Event.id =' => -1);
		}
		$this->paginate = array(
				'fields' => array('Event.id', 'Event.org_id', 'Event.orgc_id', 'Event.publish_timestamp', 'Event.distribution', 'Event.info', 'Event.date', 'Event.published'),
				'conditions' => $conditions,
				'contain' => array(
					'User' => array(
							'fields' => array(
								'User.email'
					)),
					'ShadowAttribute'=> array(
						'fields' => array(
							'ShadowAttribute.id', 'ShadowAttribute.org_id', 'ShadowAttribute.event_id'
						),
						'conditions' => array(
							'ShadowAttribute.deleted' => 0
						),
					),
		));
		$events = $this->paginate();
		$orgIds = array();
		foreach ($events as $k => $event) {
			$orgs = array();
			foreach ($event['ShadowAttribute'] as $sa) {
				if (!in_array($sa['org_id'], $orgs)) $orgs[] = $sa['org_id'];
				if (!in_array($sa['org_id'], $orgIds)) $orgIds[] = $sa['org_id'];
			}
			$events[$k]['orgArray'] = $orgs;
			$events[$k]['Event']['proposal_count'] = count($event['ShadowAttribute']);
		}
		$orgs = $this->Event->Orgc->find('list', array(
			'conditions' => array('Orgc.id' => $orgIds),
			'fields' => array('Orgc.id', 'Orgc.name')
		));
		$this->set('orgs', $orgs);
		$this->set('events', $events);
		$this->set('eventDescriptions', $this->Event->fieldDescriptions);
		$this->set('analysisLevels', $this->Event->analysisLevels);
		$this->set('distributionLevels', $this->Event->distributionLevels);
	}

	public function reportValidationIssuesEvents() {
		// search for validation problems in the events
		if (!self::_isSiteAdmin()) throw new NotFoundException();
		$results = $this->Event->reportValidationIssuesEvents();
		$result = $results[0];
		$count = $results[1];
		$this->set('result', $result);
		$this->set('count', $count);
	}

	public function addTag($id = false, $tag_id = false) {
		if (!$this->request->is('post')) {
			return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'You don\'t have permission to do that.')), 'status'=>200, 'type' => 'json'));
		}
		$rearrangeRules = array(
				'request' => false,
				'Event' => false,
				'tag_id' => 'tag',
				'event_id' => 'event',
				'id' => 'event'
		);
		$RearrangeTool = new RequestRearrangeTool();
		$this->request->data = $RearrangeTool->rearrangeArray($this->request->data, $rearrangeRules);
		if ($id === false) $id = $this->request->data['event'];
		if ($tag_id === false) $tag_id = $this->request->data['tag'];
		$conditions = array('LOWER(Tag.name) LIKE' => strtolower(trim($tag_id)));
		if (!$this->_isSiteAdmin()) {
			$conditions['Tag.org_id'] = array('0', $this->Auth->user('org_id'));
			$conditions['Tag.user_id'] = array('0', $this->Auth->user('id'));
		}
		if (!is_numeric($tag_id)) {
			$tag = $this->Event->EventTag->Tag->find('first', array('recursive' => -1, 'conditions' => $conditions));
			if (empty($tag)) return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'Invalid Tag.')), 'status'=>200, 'type' => 'json'));
			$tag_id = $tag['Tag']['id'];
		}
		$this->Event->recursive = -1;
		$event = $this->Event->read(array(), $id);

		if (!$this->_isSiteAdmin() && !$this->userRole['perm_sync']) {
			if (!$this->userRole['perm_tagger'] || ($this->Auth->user('org_id') !== $event['Event']['org_id'] && $this->Auth->user('org_id') !== $event['Event']['orgc_id'])) {
				return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'You don\'t have permission to do that.')), 'status'=>200, 'type' => 'json'));
			}
		}
		$this->Event->EventTag->Tag->id = $tag_id;
		if (!$this->Event->EventTag->Tag->exists()) {
			return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'Invalid Tag.')), 'status'=>200, 'type' => 'json'));
		}
		$tag = $this->Event->EventTag->Tag->find('first', array(
			'conditions' => array('Tag.id' => $tag_id),
			'recursive' => -1,
			'fields' => array('Tag.name')
		));
		$found = $this->Event->EventTag->find('first', array(
			'conditions' => array(
				'event_id' => $id,
				'tag_id' => $tag_id
			),
			'recursive' => -1,
		));
		$this->autoRender = false;
		if (!empty($found)) return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'Tag is already attached to this event.')), 'status'=>200, 'type' => 'json'));
		$this->Event->EventTag->create();
		if ($this->Event->EventTag->save(array('event_id' => $id, 'tag_id' => $tag_id))) {
			$event['Event']['published'] = 0;
			$date = new DateTime();
			$event['Event']['timestamp'] = $date->getTimestamp();
			$this->Event->save($event);
			$log = ClassRegistry::init('Log');
			$log->createLogEntry($this->Auth->user(), 'tag', 'Event', $id, 'Attached tag (' . $tag_id . ') "' . $tag['Tag']['name'] . '" to event (' . $id . ')', 'Event (' . $id . ') tagged as Tag (' . $tag_id . ')');
			return new CakeResponse(array('body'=> json_encode(array('saved' => true, 'success' => 'Tag added.', 'check_publish' => true)), 'status'=>200, 'type' => 'json'));
		} else {
			return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'Tag could not be added.')), 'status'=>200, 'type' => 'json'));
		}
	}

	public function removeTag($id = false, $tag_id = false, $galaxy = false) {
		if (!$this->request->is('post')) {
			$this->set('id', $id);
			$this->set('tag_id', $tag_id);
			$this->set('model', 'Event');
			$this->render('/Attributes/ajax/tagRemoveConfirmation');
		} else {
			$rearrangeRules = array(
					'request' => false,
					'Event' => false,
					'tag_id' => 'tag',
					'event_id' => 'event',
					'id' => 'event'
			);
			$RearrangeTool = new RequestRearrangeTool();
			$this->request->data = $RearrangeTool->rearrangeArray($this->request->data, $rearrangeRules);
			if ($id === false) $id = $this->request->data['event'];
			if ($tag_id === false) $tag_id = $this->request->data['tag'];
			if (empty($tag_id)) return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'Invalid ' . ($galaxy ? 'Galaxy' : 'Tag') . '.')), 'status'=>200, 'type' => 'json'));
			if (!is_numeric($tag_id)) {
				$tag = $this->Event->EventTag->Tag->find('first', array('recursive' => -1, 'conditions' => array('LOWER(Tag.name) LIKE' => strtolower(trim($tag_id)))));
				if (empty($tag)) return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'Invalid ' . ($galaxy ? 'Galaxy' : 'Tag') . '.')), 'status'=>200, 'type' => 'json'));
				$tag_id = $tag['Tag']['id'];
			}
			if (!is_numeric($id)) $id = $this->request->data['Event']['id'];
			$this->Event->recursive = -1;
			$event = $this->Event->read(array(), $id);
			// org should allow to tag too, so that an event that gets pushed can be tagged locally by the owning org
			if ((($this->Auth->user('org_id') !== $event['Event']['org_id'] && $this->Auth->user('org_id') !== $event['Event']['orgc_id']) || (!$this->userRole['perm_tagger'])) && !$this->_isSiteAdmin()) {
				return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'You don\'t have permission to do that.')), 'status'=>200, 'type' => 'json'));
			}
			$eventTag = $this->Event->EventTag->find('first', array(
				'conditions' => array(
					'event_id' => $id,
					'tag_id' => $tag_id
				),
				'recursive' => -1,
			));
			$this->autoRender = false;
			if (empty($eventTag)) return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'Invalid event - ' . ($galaxy ? 'galaxy' : 'tag') . ' combination.')), 'status'=>200, 'type' => 'json'));
			$tag = $this->Event->EventTag->Tag->find('first', array(
				'conditions' => array('Tag.id' => $tag_id),
				'recursive' => -1,
				'fields' => array('Tag.name')
			));
			if ($this->Event->EventTag->delete($eventTag['EventTag']['id'])) {
				$event['Event']['published'] = 0;
				$date = new DateTime();
				$event['Event']['timestamp'] = $date->getTimestamp();
				$this->Event->save($event);
				$log = ClassRegistry::init('Log');
				$log->createLogEntry($this->Auth->user(), 'tag', 'Event', $id, 'Removed tag (' . $tag_id . ') "' . $tag['Tag']['name'] . '" from event (' . $id . ')', 'Event (' . $id . ') untagged of Tag (' . $tag_id . ')');
				return new CakeResponse(array('body'=> json_encode(array('saved' => true, 'success' => ($galaxy ? 'Galaxy' : 'Tag') . ' removed.', 'check_publish' => true)), 'status'=>200, 'type' => 'json'));
			} else {
				return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => ($galaxy ? 'Galaxy' : 'Tag') . ' could not be removed.')), 'status'=>200, 'type' => 'json'));
			}
		}
	}

	/*
	 * adhereToWarninglists is used when querying this function via the API
	 * possible options:
	 *  - false: (default) ignore warninglists
	 *  - 'soft': Unset the IDS flag of all attributes hitting on a warninglist item
	 *  - true / 'hard': Block attributes from being added that have a hit in the warninglists
	 */
	public function freeTextImport($id, $adhereToWarninglists = false) {
		if (!$this->userRole['perm_add']) {
			throw new MethodNotAllowedException('Event not found or you don\'t have permissions to create attributes');
		}
		$event = $this->Event->find('first', array(
				'conditions' => array('Event.id' => $id),
				'fields' => array('id', 'orgc_id'),
				'recursive' => -1
		));
		$this->set('event_id', $id);
		if ($this->request->is('get')) {
			$this->layout = 'ajax';
			$this->request->data['Attribute']['event_id'] = $id;
		}

		if ($this->request->is('post')) {
			App::uses('ComplexTypeTool', 'Tools');
			$complexTypeTool = new ComplexTypeTool();
			$this->loadModel('Warninglist');
			$complexTypeTool->setTLDs($this->Warninglist->fetchTLDLists());
			if (!isset($this->request->data['Attribute'])) {
				$this->request->data = array('Attribute' => $this->request->data);
			}
			if (!isset($this->request->data['Attribute']['value'])) {
				$this->request->data['Attribute'] = array('value' => $this->request->data);
			}
			if (isset($this->request->data['Attribute']['adhereToWarninglists'])) {
				$adhereToWarninglists = $this->request->data['Attribute']['adhereToWarninglists'];
			}
			$resultArray = $complexTypeTool->checkComplexRouter($this->request->data['Attribute']['value'], 'freetext');
			foreach ($resultArray as $key => $r) {
				$temp = array();
				foreach ($r['types'] as $type) {
					$temp[$type] = $type;
				}
				$resultArray[$key]['types'] = $temp;
			}

			// remove all duplicates
			foreach ($resultArray as $k => $v) {
				for ($i = 0; $i < $k; $i++) {
					if (isset($resultArray[$i]) && $v == $resultArray[$i]) unset($resultArray[$k]);
				}
			}
			if ($this->_isRest()) {
				return $this->__pushFreetext(
					$resultArray,
					$id,
					isset($this->request->data['Attribute']['distribution']) ? $this->request->data['Attribute']['distribution'] : false,
					isset($this->request->data['Attribute']['sharing_group_id']) ? $this->request->data['Attribute']['sharing_group_id'] : false,
					$adhereToWarninglists
				);
			}
			foreach ($resultArray as $key => $result) {
				$options = array(
					'conditions' => array('OR' => array('Attribute.value1' => $result['value'], 'Attribute.value2' => $result['value'])),
					'fields' => array('Attribute.type', 'Attribute.category', 'Attribute.value', 'Attribute.comment'),
					'order' => false
				);
				$resultArray[$key]['related'] = $this->Event->Attribute->fetchAttributes($this->Auth->user(), $options);
			}
			$resultArray = array_values($resultArray);
			$typeCategoryMapping = array();
			foreach ($this->Event->Attribute->categoryDefinitions as $k => $cat) {
				foreach ($cat['types'] as $type) {
					$typeCategoryMapping[$type][$k] = $k;
				}
			}
			$distributions = $this->Event->Attribute->distributionLevels;
			$sgs = $this->Event->SharingGroup->fetchAllAuthorised($this->Auth->user(), 'name',  1);
			if (empty($sgs)) {
				unset($distributions[4]);
			}

			$this->set('proposals', $event['Event']['orgc_id'] != $this->Auth->user('org_id') && !$this->_isSiteAdmin());
			$this->set('distributions', $distributions);
			$this->set('sgs', $sgs);
			$this->set('event', $event);
			$this->set('typeList', array_keys($this->Event->Attribute->typeDefinitions));
			$this->set('defaultCategories', $this->Event->Attribute->defaultCategories);
			$this->set('typeCategoryMapping', $typeCategoryMapping);
			$this->set('resultArray', $resultArray);
			$this->set('importComment', '');
			$this->set('title', 'Freetext Import Results');
			$this->render('resolved_attributes');
		}
	}

	public function __pushFreetext($resultArray, $eventId, $distribution = false, $sg = false, $adhereToWarninglists = false) {
		if ($adhereToWarninglists) {
			$this->Warninglist = ClassRegistry::init('Warninglist');
			$warninglists = $this->Warninglist->fetchForEventView();
		}
		foreach ($resultArray as $k => $result) {
			$result['type'] = $result['default_type'];
			unset($result['default_type']);
			unset($result['types']);
			if (isset($result['default_category'])) {
				$result['category'] = $result['default_category'];
				unset($result['default_category']);
			} else {
				$result['category'] = $this->Event->Attribute->defaultCategories[$result['type']];
			}
			if ($distribution === false) {
				if (Configure::read('MISP.default_attribute_distribution') != null) {
					if (Configure::read('MISP.default_attribute_distribution') == 'event') {
						$distribution = 5;
					} else {
						$distribution = Configure::read('MISP.default_attribute_distribution');
					}
				} else {
					$distribution = 0;
				}
			}
			$result['distribution'] = $distribution;
			$result['event_id'] = $eventId;
			$resultArray[$k] = $result;
			if ($adhereToWarninglists) {
				if (!$this->Warninglist->filterWarninglistAttributes($warninglists, $result)) {
					if ($adhereToWarninglists == 'soft') {
						$result['to_ids'] = 0;
					} else {
						unset($resultArray[$k]);
						continue;
					}
				}
			}
			$this->Event->Attribute->create();
			if (!$this->Event->Attribute->save($result)) {
				unset($resultArray[$k]);
			}
		}
		$resultArray = array_values($resultArray);
		return $this->RestResponse->viewData($resultArray, $this->response->type());
	}

	public function saveFreeText($id) {
		if (!$this->userRole['perm_add']) {
			throw new MethodNotAllowedException('Event not found or you don\'t have permissions to create attributes');
		}
		if ($this->request->is('post')) {
			$event = $this->Event->find('first', array(
				'conditions' => array('id' => $id),
				'recursive' => -1,
				'fields' => array('orgc_id', 'id', 'distribution', 'published', 'uuid'),
			));
			if (!$this->_isSiteAdmin() && !empty($event) && $event['Event']['orgc_id'] != $this->Auth->user('org_id')) $objectType = 'ShadowAttribute';
			else if ($this->_isSiteAdmin() && isset($this->request->data['Attribute']['force']) && $this->request->data['Attribute']['force']) $objectType = 'ShadowAttribute';
			else $objectType = 'Attribute';
			$saved = 0;
			$failed = 0;
			$attributes = json_decode($this->request->data['Attribute']['JsonObject'], true);
			$attributeSources = array('attributes', 'ontheflyattributes');
			$ontheflyattributes = array();
			foreach ($attributeSources as $source) {
				foreach (${$source} as $k => $attribute) {
					if ($attribute['type'] == 'ip-src/ip-dst') {
						$types = array('ip-src', 'ip-dst');
					} else if ($attribute['type'] == 'ip-src|port/ip-dst|port') {
						$types = array('ip-src|port', 'ip-dst|port');
					} else if ($attribute['type'] == 'malware-sample') {
						if (!isset($attribute['data_is_handled']) || !$attribute['data_is_handled']) {
							$result = $this->Event->Attribute->handleMaliciousBase64($id, $attribute['value'], $attribute['data'], array('md5', 'sha1', 'sha256'), $objectType == 'ShadowAttribute' ? true : false);
							if (!$result['success']) {
								$failed++;
								continue;
							}
							$attribute['data'] = $result['data'];
							$shortValue = $attribute['value'];
							$attribute['value'] = $shortValue . '|' . $result['md5'];
							$additionalHashes = array('sha1', 'sha256');
							foreach ($additionalHashes as $hash) {
								$temp = $attribute;
								$temp['type'] = 'filename|' . $hash;
								$temp['value'] = $shortValue . '|' . $result[$hash];
								unset($temp['data']);
								$ontheflyattributes[] = $temp;
							}
						}
						$types = array($attribute['type']);
					} else {
						$types = array($attribute['type']);
					}
					foreach ($types as $type) {
						$this->Event->$objectType->create();
						$attribute['type'] = $type;
						if (empty($attribute['comment'])) $attribute['comment'] = $this->request->data['Attribute']['default_comment'];
						$attribute['event_id'] = $id;
						if ($objectType == 'ShadowAttribute') {
							$attribute['org_id'] = $this->Auth->user('org_id');
							$attribute['event_org_id'] = $event['Event']['orgc_id'];
							$attribute['email'] = $this->Auth->user('email');
							$attribute['event_uuid'] = $event['Event']['uuid'];
						}
						$AttributSave = $this->Event->$objectType->save($attribute);
						if ($AttributSave) {
							// If Tags, attache each tags to attribut
							if (!empty($attribute['tags'])) {
								foreach (explode(",",$attribute['tags']) as $tagName){
									$this->loadModel('Tag');
									$TagId = $this->Tag->captureTag(array('name' => $tagName),array('Role' => $this->userRole));
									$this->loadModel('AttributeTag');
									if (!$this->AttributeTag->attachTagToAttribute($AttributSave['Attribute']['id'],$id,$TagId)) {
										throw new MethodNotAllowedException('Could not add tags.');
									}
								}
							}
							$saved++;
						} else {
							$failed++;
						}
					}
				}
			}
			$emailResult = '';
			$messageScope = $objectType == 'ShadowAttribute' ? 'proposals' : 'attributes';
			if ($saved > 0) {
				if ($objectType != 'ShadowAttribute') {
					$event = $this->Event->find('first', array(
							'conditions' => array('Event.id' => $id),
							'recursive' => -1
					));
					if ($event['Event']['published'] == 1) {
						$event['Event']['published'] = 0;
					}
					$date = new DateTime();
					$event['Event']['timestamp'] = $date->getTimestamp();
					$this->Event->save($event);
				} else {
					if (!$this->Event->ShadowAttribute->sendProposalAlertEmail($id)) {
						$emailResult = " but sending out the alert e-mails has failed for at least one recipient";
					}
				}
			}
			if ($failed > 0) {
				$this->Session->setFlash($saved . ' ' . $messageScope . ' created' . $emailResult . '. ' . $failed . ' ' . $messageScope . ' could not be saved. This may be due to attributes with similar values already existing.');
			} else {
				$this->Session->setFlash($saved . ' ' . $messageScope . ' created' . $emailResult . '.');
			}
			$this->redirect(array('controller' => 'events', 'action' => 'view', $id));
		} else {
			throw new MethodNotAllowedException();
		}
	}

	public function stix2($key, $id) {
		if ($key != 'download') {
			// check if the key is valid -> search for users based on key
			$user = $this->checkAuthUser($key);
			if (!$user) {
				throw new UnauthorizedException('This authentication key is not authorized to be used for exports. Contact your administrator.');
			}
		} else {
			if (!$this->Auth->user('id')) {
				throw new UnauthorizedException('You have to be logged in to do that.');
			}
		}
		$result = $this->Event->stix2($id, $this->Auth->user());
		$this->header('Content-Disposition: download; filename="misp.stix2.event' . $id . '.json"');
		return $this->RestResponse->viewData($result, 'application/json', false, true, "misp.stix2.event" . $id . ".json");
	}

	public function stix($key, $id = false, $withAttachments = false, $tags = false, $from = false, $to = false, $last = false) {
		if ($key != 'download') {
			// check if the key is valid -> search for users based on key
			$user = $this->checkAuthUser($key);
			if (!$user) {
				throw new UnauthorizedException('This authentication key is not authorized to be used for exports. Contact your administrator.');
			}
		} else {
			if (!$this->Auth->user('id')) {
				throw new UnauthorizedException('You have to be logged in to do that.');
			}
		}

		// request handler for POSTed queries. If the request is a post, the parameters (apart from the key) will be ignored and replaced by the terms defined in the posted xml object.
		// The correct format for a posted xml is a "request" root element, as shown by the examples below:
		// For XML: <request><id>!3&amp;!4</id><tags>OSINT</tags></request>
		// This would return all OSINT tagged events except for event #3 and #4
		if ($this->request->is('post')) {
			if (empty($this->request->data)) {
				throw new BadRequestException('Either specify the search terms in the url, or POST an xml (with the root element being "request".');
			} else {
				$data = $this->request->data;
			}
			$paramArray = array('id', 'withAttachment', 'tags', 'from', 'to', 'last');
			foreach ($paramArray as $p) {
				if (isset($data['request'][$p])) ${$p} = $data['request'][$p];
				else ${$p} = null;
			}
		}

		$simpleFalse = array('id', 'withAttachments', 'tags', 'from', 'to', 'last');
		foreach ($simpleFalse as $sF) {
			if (!is_array(${$sF}) && (${$sF} === 'null' || ${$sF} == '0' || ${$sF} === false || strtolower(${$sF}) === 'false')) {
				${$sF} = false;
			}
		}
		if ($from) $from = $this->Event->dateFieldCheck($from);
		if ($to) $to = $this->Event->dateFieldCheck($to);
		if ($last) $last = $this->Event->resolveTimeDelta($last);

		// set null if a null string is passed
		$numeric = false;
		if (is_numeric($id)) $numeric = true;
		// set the export type based on the request
		if ($this->response->type() === 'application/json') {
			$returnType = 'json';
		} else {
			$returnType = 'xml';
			$this->response->type('xml');	// set the content type
			$this->layout = 'xml/default';
		}
		$result = $this->Event->stix($id, $tags, $withAttachments, $this->Auth->user(), $returnType, $from, $to, $last);
		if ($result['success'] == 1) {
			// read the output file and pass it to the view
			if (!$numeric) {
				$this->header('Content-Disposition: download; filename="misp.stix.event.collection.' . $returnType . '"');
			} else {
				$this->header('Content-Disposition: download; filename="misp.stix.event' . $id . '.' . $returnType . '"');
			}
			$this->set('data', $result['data']);
		} else {
			throw new Exception(h($result['message']));
		}
	}

	public function filterEventIdsForPush() {
		if (!$this->userRole['perm_sync']) throw new MethodNotAllowedException('You do not have the permission to do that.');
		if ($this->request->is('post')) {
			$incomingIDs = array();
			$incomingEvents = array();
			foreach ($this->request->data as $event) {
				$incomingIDs[] = $event['Event']['uuid'];
				$incomingEvents[$event['Event']['uuid']] = $event['Event']['timestamp'];
			}
			$events = $this->Event->find('all', array(
				'conditions' => array('Event.uuid' => $incomingIDs),
				'recursive' => -1,
				'fields' => array('Event.uuid', 'Event.timestamp', 'Event.locked'),
			));
			foreach ($events as $k => $v) {
				if ($v['Event']['timestamp'] >= $incomingEvents[$v['Event']['uuid']]) {
					unset($incomingEvents[$v['Event']['uuid']]);
					continue;
				}
				if ($v['Event']['locked'] == 0) {
					unset($incomingEvents[$v['Event']['uuid']]);
				}
			}
			$this->set('result', array_keys($incomingEvents));
		}
	}

	public function checkuuid($uuid) {
		if (!$this->userRole['perm_sync']) throw new MethodNotAllowedException('You do not have the permission to do that.');
		$events = $this->Event->find('first', array(
				'conditions' => array('Event.uuid' => $uuid),
				'recursive' => -1,
				'fields' => array('Event.uuid'),
		));
		$this->set('result', array('result' => empty($events)));
	}

	public function pushProposals($uuid) {
		$message= "";
		$success = true;
		$counter = 0;
		if (!$this->userRole['perm_sync'] || !$this->userRole['perm_add']) throw new MethodNotAllowedException('You do not have the permission to do that.');
		if ($this->request->is('post')) {
			$event = $this->Event->find('first', array(
					'conditions' => array('Event.uuid' => $uuid),
					'contains' => array('ShadowAttribute', 'Attribute' => array(
						'fields' => array('id', 'uuid', 'event_id'),
					)),
					'fields' => array('Event.uuid', 'Event.id'),
			));
			if (empty($event)) {
				$message = "Event not found.";
				$success = false;
			} else {
				foreach ($this->request->data as $k => $sa) {
					if (isset($event['ShadowAttribute'])) {
						foreach ($event['ShadowAttribute'] as $oldk => $oldsa) {
							$temp = json_encode($oldsa);
							if ($sa['event_uuid'] == $oldsa['event_uuid'] && $sa['value'] == $oldsa['value'] && $sa['type'] == $oldsa['type'] && $sa['category'] == $oldsa['category'] && $sa['to_ids'] == $oldsa['to_ids']) {
								if ($oldsa['timestamp'] < $sa['timestamp']) {
									$this->Event->ShadowAttribute->delete($oldsa['id']);
								} else {
									continue 2;
								}
							}
						}
					}
					$sa['event_id'] = $event['Event']['id'];
					if ($sa['old_id'] != 0) {
						foreach ($event['Attribute'] as $attribute) {
							if ($sa['uuid'] == $attribute['uuid']) {
								$sa['old_id'] = $attribute['id'];
							}
						}
					}
					if (isset($sa['id'])) unset($sa['id']);
					$this->Event->ShadowAttribute->create();
					if (!$this->Event->ShadowAttribute->save(array('ShadowAttribute' => $sa))) {
						$message = "Some of the proposals could not be saved.";
						$success = false;
					} else {
						$counter++;
					}
					if (!$sa['deleted']) $this->Event->ShadowAttribute->__sendProposalAlertEmail($event['Event']['id']);
				}
			}
			if ($success) {
				if ($counter) {
					$message = $counter . " Proposal(s) added.";
				} else {
					$message = "Nothing to update.";
				}
			}
			$this->set('data', array('success' => $success, 'message' => $message, 'counter' => $counter));
			$this->set('_serialize', 'data');
		}
	}

	public function exportChoice($id) {
		if (!is_numeric($id)) throw new MethodNotAllowedException('Invalid ID');
		$event = $this->Event->fetchEvent($this->Auth->user(), array('eventid' => $id));
		if (empty($event)) throw new NotFoundException('Event not found or you are not authorised to view it.');
		$event = $event[0];
		$exports = array(
			'xml' => array(
					'url' => '/events/restSearch/download/false/false/false/false/false/false/false/false/false/' . $id . '/false.xml',
					'text' => 'MISP XML (metadata + all attributes)',
					'requiresPublished' => false,
					'checkbox' => true,
					'checkbox_text' => 'Encode Attachments',
					'checkbox_set' => '/events/restSearch/download/false/false/false/false/false/false/false/false/false/' . $id . '/true.xml',
					'checkbox_default' => true
			),
			'json' => array(
					'url' => '/events/restSearch/download/false/false/false/false/false/false/false/false/false/' . $id . '/false.json',
					'text' => 'MISP JSON (metadata + all attributes)',
					'requiresPublished' => false,
					'checkbox' => true,
					'checkbox_text' => 'Encode Attachments',
					'checkbox_set' => '/events/restSearch/download/false/false/false/false/false/false/false/false/false/' . $id . '/true.json',
					'checkbox_default' => true
			),
			'openIOC' => array(
					'url' => '/events/downloadOpenIOCEvent/download/' . $id,
					'text' => 'OpenIOC (all indicators marked to IDS)',
					'requiresPublished' => true,
					'checkbox' => false,
			),
			'csv' => array(
					'url' => '/events/csv/download/' . $id,
					'text' => 'CSV',
					'requiresPublished' => true,
					'checkbox' => true,
					'checkbox_text' => 'Include non-IDS marked attributes',
					'checkbox_set' => '/events/csv/download/' . $id . '/1'
			),
			'stix_xml' => array(
					'url' => '/events/stix/download/' . $id . '.xml',
					'text' => 'STIX XML (metadata + all attributes)',
					'requiresPublished' => true,
					'checkbox' => true,
					'checkbox_text' => 'Encode Attachments',
					'checkbox_set' => '/events/stix/download/' . $id . '/true.xml'
			),
			'stix_json' => array(
					'url' => '/events/stix/download/' . $id . '.json',
					'text' => 'STIX JSON (metadata + all attributes)',
					'requiresPublished' => true,
					'checkbox' => true,
					'checkbox_text' => 'Encode Attachments',
					'checkbox_set' => '/events/stix/download/' . $id . '/true.json'
			),
			'stix_json' => array(
					'url' => '/events/stix2/download/' . $id . '.json',
					'text' => 'STIX2 (requires the STIX 2 library)',
					'requiresPublished' => false,
					'checkbox' => false
			),
			'rpz' => array(
					'url' => '/attributes/rpz/download/false/' . $id,
					'text' => 'RPZ Zone file',
					'requiresPublished' => true,
					'checkbox' => false,
			),
			'suricata' => array(
					'url' => '/events/nids/suricata/download/' . $id,
					'text' => 'Download Suricata rules',
					'requiresPublished' => true,
					'checkbox' => false,
			),
			'snort' => array(
					'url' => '/events/nids/snort/download/' . $id,
					'text' => 'Download Snort rules',
					'requiresPublished' => true,
					'checkbox' => false,
			),
			'bro' => array(
					'url' => '/attributes/bro/download/all/false/' . $id,
					'text' => 'Download Bro rules',
					'requiresPublished' => true,
					'checkbox' => false
			),
			'text' => array(
					'url' => '/attributes/text/download/all/false/' . $id,
					'text' => 'Export all attribute values as a text file',
					'requiresPublished' => true,
					'checkbox' => true,
					'checkbox_text' => 'Include non-IDS marked attributes',
					'checkbox_set' => '/attributes/text/download/all/false/' . $id . '/true'
			),
		);
		if ($event['Event']['published'] == 0) {
			foreach ($exports as $k => $export) {
				if ($export['requiresPublished']) unset($exports[$k]);
			}
			$exports['csv'] = array(
				'url' => '/events/csv/download/' . $id . '/1',
				'text' => 'CSV (event not published, IDS flag ignored)',
				'requiresPublished' => false,
				'checkbox' => false
			);
		}
		$this->loadModel('Module');
		$modules = $this->Module->getEnabledModules($this->Auth->user(), false, 'Export');
		if (is_array($modules) && !empty($modules)) {
			foreach ($modules['modules'] as $module) {
				$exports[$module['name']] = array(
						'url' => '/events/exportModule/' . $module['name'] . '/' . $id,
						'text' => Inflector::humanize($module['name']),
						'requiresPublished' => true,
						'checkbox' => false,
				);
			}
		}
		$this->set('exports', $exports);
		$this->set('id', $id);
		$this->render('ajax/exportChoice');
	}

	public function importChoice($id) {
		if (!is_numeric($id)) throw new MethodNotAllowedException('Invalid ID');
		$event = $this->Event->fetchEvent($this->Auth->user(), array('eventid' => $id));
		if (empty($event)) throw new NotFoundException('Event not found or you are not authorised to view it.');
		$event = $event[0];
		$imports = array(
				'freetext' => array(
						'url' => '/events/freeTextImport/' . $id,
						'text' => 'Freetext Import',
						'ajax' => true,
						'target' => 'popover_form'
				),
				'template' => array(
						'url' => '/templates/templateChoices/' . $id,
						'text' => 'Populate using a Template',
						'ajax' => true,
						'target' => 'popover_form'
				),
				'OpenIOC' => array(
						'url' => '/events/addIOC/' . $id,
						'text' => 'OpenIOC Import',
						'ajax' => false,
				),
				'ThreatConnect' => array(
						'url' => '/attributes/add_threatconnect/' . $id,
						'text' => 'ThreatConnect Import',
						'ajax' => false
				)
		);
		$this->loadModel('Module');
		$modules = $this->Module->getEnabledModules($this->Auth->user(), false, 'Import');
		if (is_array($modules) && !empty($modules)) {
			foreach ($modules['modules'] as $k => $module) {
				$imports[$module['name']] = array(
						'url' => '/events/importModule/' . $module['name'] . '/' . $id,
						'text' => Inflector::humanize($module['name']),
						'ajax' => false
				);
			}
		}
		$this->set('imports', $imports);
		$this->set('id', $id);
		$this->render('ajax/importChoice');
	}

	// API for pushing samples to MISP
	// Either send it to an existing event, or let MISP create a new one automatically
	public function upload_sample($event_id = null, $advanced = false) {
		$this->loadModel('Log');
		$hashes = array('md5' => 'malware-sample', 'sha1' => 'filename|sha1', 'sha256' => 'filename|sha256');
		$categoryDefinitions = $this->Event->Attribute->categoryDefinitions;
		$categories = array();
		foreach ($categoryDefinitions as $k => $v) {
			if (in_array('malware-sample', $v['types']) && !in_array($k, $categories)) {
				$categories[] = $k;
			}
		}
		$default_distribution = !empty(Configure::read('MISP.default_attribute_distribution')) ? Configure::read('MISP.default_attribute_distribution') : 5;
		if ($default_distribution == 'event') $default_distribution = 5;
		$parameter_options = array(
				'distribution' => array('valid_options' => array(0, 1, 2, 3, 5), 'default' => $default_distribution),
				'threat_level_id' => array('valid_options' => array(1, 2, 3, 4), 'default' => 4),
				'analysis' => array('valid_options' => array(0, 1, 2), 'default' => 0),
				'info' => array('default' =>  'Malware samples uploaded on ' . date('Y-m-d')),
				'to_ids' => array('valid_options' => array(0, 1), 'default' => 1),
				'category' => array('valid_options' => $categories, 'default' => 'Payload installation'),
				'comment' => array('default' => '')
		);

		if (!$this->userRole['perm_auth']) throw new MethodNotAllowedException('This functionality requires API key access.');
		if (!$this->request->is('post')) throw new MethodNotAllowedException('Please POST the samples as described on the automation page.');
		if ($this->response->type() === 'application/json') {
			$data = $this->request->input('json_decode', true);
		} else if ($this->response->type() === 'application/xml') {
			$data = $this->request->data;
		} else {
			throw new BadRequestException('Please POST the samples as described on the automation page.');
		}

		if (isset($data['request'])) $data = $data['request'];
		foreach ($parameter_options as $k => $v) {
			if (isset($data[$k])) {
				if (isset($v['valid_options']) && !in_array($data[$k], $v['valid_options'])) {
					$data['settings'][$k] = $v['default'];
				} else {
					$data['settings'][$k] = $data[$k];
				}
				unset($data[$k]);
			} else {
				$data['settings'][$k] = $v['default'];
			}
		}
		if (isset($data['files'])) {
			foreach ($data['files'] as $k => $file) {
				if (!isset($file['filename']) || !isset($file['data'])) {
					unset($data['files'][$k]);
				} else {
					$data['files'][$k]['md5'] = md5(base64_decode($file['data']));
				}
			}
		}

		if (empty($data['files'])) {
			throw new BadRequestException('No samples received, or samples not in the correct format. Please refer to the API documentation on the automation page.');
		}
		if (isset($event_id)) $data['settings']['event_id'] = $event_id;
		if (isset($data['settings']['event_id'])) {
			$this->Event->id = $data['settings']['event_id'];
			if (!$this->Event->exists()) throw new NotFoundException('Event not found');
		}
		if (isset($data['advanced'])) $advanced = $data['advanced'];

		// check if the user has permission to create attributes for an event, if the event ID has been passed
		// If not, create an event
		if (isset($data['settings']['event_id']) && !empty($data['settings']['event_id']) && is_numeric($data['settings']['event_id'])) {
			$conditions = array('Event.id' => $data['settings']['event_id']);
			if (!$this->_isSiteAdmin()) {
				$conditions[] = array('Event.orgc_id' => $this->Auth->user('org_id'));
				if (!$this->userRole['perm_modify_org']) {
					$conditions[] = array('Event.user_id' => $this->Auth->user('id'));
				}
			}
			$event = $this->Event->find('first', array(
				'recursive' => -1,
				'conditions' => $conditions,
				'fields' => array('id'),
			));
			if (empty($event)) throw new NotFoundException('Event not found.');
			$this->Event->id = $data['settings']['event_id'];
			$date = new DateTime();
			$this->Event->saveField('timestamp', $date->getTimestamp());
			$this->Event->saveField('published', 0);
		} else {
			$this->Event->create();
			if ($data['settings']['distribution'] == 5) throw new BadRequestException('Distribution level 5 is not supported when uploading a sample without passing an event ID. Distribution level 5 is meant to take on the distribution level of an existing event.');
			$result = $this->Event->save(
				array(
					'info' => $data['settings']['info'],
					'analysis' => $data['settings']['analysis'],
					'threat_level_id' => $data['settings']['threat_level_id'],
					'distribution' => $data['settings']['distribution'],
					'date' => date('Y-m-d'),
					'orgc_id' => $this->Auth->user('org_id'),
					'org_id' => $this->Auth->user('org_id'),
					'user_id' => $this->Auth->user('id'),
				)
			);
			if (!$result) {
				$this->Log->save(array(
						'org' => $this->Auth->user('Organisation')['name'],
						'model' => 'Event',
						'model_id' => 0,
						'email' => $this->Auth->user('email'),
						'action' => 'upload_sample',
						'user_id' => $this->Auth->user('id'),
						'title' => 'Error: Failed to create event using the upload sample functionality',
						'change' => 'There was an issue creating an event (' . $data['settings']['info'] . '). The validation errors were: ' . json_encode($this->Event->validationErrors),
				));
				throw new BadRequestException('The creation of a new event with the supplied information has failed.');
			}
			$data['settings']['event_id'] = $this->Event->id;
			$event_id = $this->Event->id;
		}

		if (!isset($data['settings']['to_ids']) || !in_array($data['settings']['to_ids'], array('0', '1', 0, 1))) $data['settings']['to_ids'] = 1;
		$successCount = 0;
		$errors = array();
		App::uses('FileAccessTool', 'Tools');
		$fileAccessTool = new FileAccessTool();
		foreach ($data['files'] as $file) {
			$tmpdir = Configure::read('MISP.tmpdir') ? Configure::read('MISP.tmpdir') : '/var/www/MISP/app/tmp';
			$tmpfile = $fileAccessTool->createTempFile($tmpdir, $prefix = 'MISP_upload');
			$fileAccessTool->writeToFile($tmpfile, base64_decode($file['data']));
			$tmpfile = new File($tmpfile);
			if ($advanced) {
				$result = $this->Event->Attribute->advancedAddMalwareSample(
					$event_id,
					$data['settings'],
					$file['filename'],
					$tmpfile
				);
				if ($result) $successCount++;
				else $errors[] = $file['filename'];
			} else {
				$result = $this->Event->Attribute->simpleAddMalwareSample(
					$event_id,
					$data['settings'],
					$file['filename'],
					$tmpfile
				);
				if ($result) $successCount++;
				else $errors[] = $file['filename'];
			}
			if (!empty($result)) {
				foreach ($result['Object'] as $object) {
					if (isset($data['settings']['distribution'])) $object['distribution'] = $data['settings']['distribution'];
					$object['sharing_group_id'] = isset($data['settings']['sharing_group_id']) ? $data['settings']['sharing_group_id'] : 0;
					if (!empty($object['Attribute'])) {
						foreach ($object['Attribute'] as $k => $attribute) {
							if ($attribute['value'] == $tmpfile->name) {
								$object['Attribute'][$k]['value'] = $file['filename'];
							}
							if (isset($data['settings']['distribution'])) $object['Attribute'][$k]['distribution'] = $data['settings']['distribution'];
							$object['Attribute'][$k]['sharing_group_id'] = isset($data['settings']['sharing_group_id']) ? $data['settings']['sharing_group_id'] : 0;
						}
					}
					$this->loadModel('MispObject');
					$this->MispObject->captureObject(array('Object' => $object), $event_id, $this->Auth->user());
				}
				if (!empty($result['ObjectReference'])) {
					foreach ($result['ObjectReference'] as $reference) {
						$this->MispObject->ObjectReference->smartSave($reference, $event_id);
					}
				}
			}
			$fileAccessTool->deleteFile($tmpfile->path);
		}
		if (!empty($errors)) {
			$this->set('errors', $errors);
			if ($successCount > 0) {
				$this->set('name', 'Partial success');
				$this->set('message', 'Successfuly saved ' . $successCount . ' sample(s), but some samples could not be saved.');
				$this->set('url', '/events/view/' . $data['settings']['event_id']);
				$this->set('id', $data['settings']['event_id']);
				$this->set('_serialize', array('name', 'message', 'url', 'id', 'errors'));
			} else {
				$this->set('name', 'Failed');
				$this->set('message', 'Failed to save any of the supplied samples.');
				$this->set('_serialize', array('name', 'message', 'errors'));
			}
		} else {
			$this->set('name', 'Success');
			$this->set('message', 'Success, saved all attributes.');
			$this->set('url', '/events/view/' . $data['settings']['event_id']);
			$this->set('id', $data['settings']['event_id']);
			$this->set('_serialize', array('name', 'message', 'url', 'id'));
		}
		$this->view($data['settings']['event_id']);
		$this->render('view');
	}

	public function viewGraph($id) {
		$event = $this->Event->fetchEvent($this->Auth->user(), array('eventid' => $id));
		if (empty($event)) throw new MethodNotAllowedException('Invalid Event.');
		$this->set('event', $event[0]);
		$this->set('scope', 'event');
		$this->set('id', $id);
	}

/*
	public function deleteNode($id) {
		if (!$this->request->is('post')) throw new MethodNotAllowedException('Only POST requests are allowed.');
		App::uses('CorrelationGraphTool', 'Tools');
		$grapher = new CorrelationGraphTool();
		$grapher->construct($this->Event, $this->Taxonomy, $this->GalaxyCluster, $this->Auth->user(), $this->request->data);
		$json = $grapher->deleteNode($id);
	}
*/

	public function updateGraph($id, $type = 'event') {
		$validTools = array('event', 'galaxy', 'tag');
		if (!in_array($type, $validTools)) throw new MethodNotAllowedException('Invalid type.');
		$this->loadModel('Taxonomy');
		$this->loadModel('GalaxyCluster');
		App::uses('CorrelationGraphTool', 'Tools');
		$grapher = new CorrelationGraphTool();
		$data = $this->request->is('post') ? $this->request->data : array();
		$grapher->construct($this->Event, $this->Taxonomy, $this->GalaxyCluster, $this->Auth->user(), $data);
		$json = $grapher->buildGraphJson($id, $type);

		array_walk_recursive($json, function(&$item, $key){
			if(!mb_detect_encoding($item, 'utf-8', true)){
				$item = utf8_encode($item);
			}
		});
		$this->response->type('json');
		return new CakeResponse(array('body' => json_encode($json), 'status' => 200, 'type' => 'json'));
	}

	public function delegation_index() {
		$this->loadModel('EventDelegation');
		$delegatedEvents = $this->EventDelegation->find('list', array(
				'conditions' => array('EventDelegation.org_id' => $this->Auth->user('org_id')),
				'fields' => array('event_id')
		));
		$this->Event->contain(array('User.email', 'EventTag' => array('Tag')));
		$tags = $this->Event->EventTag->Tag->find('all', array('recursive' => -1));
		$tagNames = array('None');
		foreach ($tags as $k => $v) {
			$tagNames[$v['Tag']['id']] = $v['Tag']['name'];
		}
		$this->set('tags', $tagNames);
		$this->paginate = array(
			'limit' => 60,
			'maxLimit' => 9999,	// LATER we will bump here on a problem once we have more than 9999 events <- no we won't, this is the max a user van view/page.
			'order' => array(
					'Event.timestamp' => 'DESC'
			),
			'contain' => array(
					'Org' => array('fields' => array('id', 'name')),
					'Orgc' => array('fields' => array('id', 'name')),
					'SharingGroup' => array('fields' => array('id', 'name')),
					'ThreatLevel' => array('fields' => array('ThreatLevel.name'))

			),
			'conditions' => array('Event.id' => $delegatedEvents),
		);

		$this->set('events', $this->paginate());
		$threat_levels = $this->Event->ThreatLevel->find('all');
		$this->set('threatLevels', Set::combine($threat_levels, '{n}.ThreatLevel.id', '{n}.ThreatLevel.name'));
		$this->set('eventDescriptions', $this->Event->fieldDescriptions);
		$this->set('analysisLevels', $this->Event->analysisLevels);
		$this->set('distributionLevels', $this->Event->distributionLevels);

		$shortDist = array(0 => 'Organisation', 1 => 'Community', 2 => 'Connected', 3 => 'All', 4 => ' sharing Group');
		$this->set('shortDist', $shortDist);
		$this->set('ajax', false);
		$this->set('simple', true);
		$this->Event->contain(array('User.email', 'EventTag' => array('Tag')));
		$tags = $this->Event->EventTag->Tag->find('all', array('recursive' => -1));
		$tagNames = array('None');
		foreach ($tags as $k => $v) {
			$tagNames[$v['Tag']['id']] = $v['Tag']['name'];
		}
		$this->set('tags', $tagNames);
		$this->render('index');
	}

	// expects an attribute ID and the module to be used
	public function queryEnrichment($attribute_id, $module = false, $type = 'Enrichment') {
		if (!Configure::read('Plugin.' . $type . '_services_enable')) throw new MethodNotAllowedException($type . ' services are not enabled.');
		$attribute = $this->Event->Attribute->fetchAttributes($this->Auth->user(), array('conditions' => array('Attribute.id' => $attribute_id)));
		if (empty($attribute)) throw new MethodNotAllowedException('Attribute not found or you are not authorised to see it.');
		if ($this->request->is('ajax')) {
			$this->loadModel('Module');
			$enabledModules = $this->Module->getEnabledModules($this->Auth->user(), false, $type);
			if (!is_array($enabledModules) || empty($enabledModules)) throw new MethodNotAllowedException('No valid ' . $type . ' options found for this attribute.');
			$modules = array();
			foreach ($enabledModules['modules'] as $module) {
				if (in_array($attribute[0]['Attribute']['type'], $module['mispattributes']['input'])) {
					$modules[] = array('name' => $module['name'], 'description' => $module['meta']['description']);
				}
			}
			foreach (array('attribute_id', 'modules') as $viewVar) $this->set($viewVar, $$viewVar);
			$this->set('type', $type);
			$this->render('ajax/enrichmentChoice');
		} else {
			$this->loadModel('Module');
			$enabledModules = $this->Module->getEnabledModules($this->Auth->user(), false, $type);
			if (!is_array($enabledModules) || empty($enabledModules)) throw new MethodNotAllowedException('No valid ' . $type . ' options found for this attribute.');
			$options = array();
			foreach ($enabledModules['modules'] as $temp) {
				if ($temp['name'] == $module) {
					if (isset($temp['meta']['config'])) {
						foreach ($temp['meta']['config'] as $conf) {
							$options[$conf] = Configure::read('Plugin.' . $type . '_' . $module . '_' . $conf);
						}
					}
				}
			}
			$data = array('module' => $module, $attribute[0]['Attribute']['type'] => $attribute[0]['Attribute']['value'], 'event_id' => $attribute[0]['Attribute']['event_id']);
			if ($this->Event->Attribute->typeIsAttachment($attribute[0]['Attribute']['type'])) {
				$data['data'] = $this->Event->Attribute->base64EncodeAttachment($attribute[0]['Attribute']);
			}
			if (!empty($options)) $data['config'] = $options;
			$data = json_encode($data);
			$result = $this->Module->queryModuleServer('/query', $data, false, $type);
			if (!$result) throw new MethodNotAllowedException($type . ' service not reachable.');
			if (isset($result['error'])) $this->Session->setFlash($result['error']);
			if (!is_array($result)) throw new Exception($result);
			$resultArray = $this->Event->handleModuleResult($result, $attribute[0]['Attribute']['event_id']);
			if (isset($result['comment']) && $result['comment'] != "") {
				$importComment = $result['comment'];
			}
			else {
				$importComment = $attribute[0]['Attribute']['value'] . ': Enriched via the ' . $module . ($type != 'Enrichment' ? ' ' . $type : '')  . ' module';
			}
			$typeCategoryMapping = array();
			foreach ($this->Event->Attribute->categoryDefinitions as $k => $cat) {
				foreach ($cat['types'] as $type) {
					$typeCategoryMapping[$type][$k] = $k;
				}
			}
			foreach ($resultArray as $key => $result) {
				$options = array(
						'conditions' => array('OR' => array('Attribute.value1' => $result['value'], 'Attribute.value2' => $result['value'])),
						'fields' => array('Attribute.type', 'Attribute.category', 'Attribute.value', 'Attribute.comment'),
						'order' => false
				);
				$resultArray[$key]['related'] = $this->Event->Attribute->fetchAttributes($this->Auth->user(), $options);
				if (isset($result['data'])) {
					App::uses('FileAccessTool', 'Tools');
					$fileAccessTool = new FileAccessTool();
					$tmpdir = Configure::read('MISP.tmpdir') ? Configure::read('MISP.tmpdir') : '/tmp';
					$tempFile = $fileAccessTool->createTempFile($tmpdir, $prefix = 'MISP');
					$fileAccessTool->writeToFile($tempFile, $result['data']);
					$resultArray[$key]['data'] = basename($tempFile) . '|' . filesize($tempFile);
				}
			}
			$distributions = $this->Event->Attribute->distributionLevels;
			$sgs = $this->Event->SharingGroup->fetchAllAuthorised($this->Auth->user(), 'name',  1);
			if (empty($sgs)) {
				unset($distributions[4]);
			}
			$this->set('distributions', $distributions);
			$this->set('sgs', $sgs);
			$this->set('type', $type);
			$this->set('event', array('Event' => $attribute[0]['Event']));
			$this->set('resultArray', $resultArray);
			$this->set('typeList', array_keys($this->Event->Attribute->typeDefinitions));
			$this->set('defaultCategories', $this->Event->Attribute->defaultCategories);
			$this->set('typeCategoryMapping', $typeCategoryMapping);
			$this->set('title', 'Enrichment Results');
			$this->set('importComment', $importComment);
			$this->render('resolved_attributes');
		}
	}

	public function importModule($module, $eventId) {
		$this->loadModel('Module');
		$moduleName = $module;
		$module = $this->Module->getEnabledModule($module, 'Import');
		if (!is_array($module)) throw new MethodNotAllowedException($module);
		if (!isset($module['mispattributes']['inputSource'])) $module['mispattributes']['inputSource'] = array('paste');
		if ($this->request->is('post')) {
			$fail = false;
			$modulePayload = array(
					'module' => $module['name'],
					'event_id' => $eventId
			);
			if (isset($module['meta']['config'])) {
				foreach ($module['meta']['config'] as $conf) {
					$modulePayload['config'][$conf] = Configure::read('Plugin.Import_' . $moduleName . '_' . $conf);
				}
			}
			foreach ($module['mispattributes']['userConfig'] as $configName => $config) {
				if (!$fail) {
					if (isset($config['validation'])) {
						if ($config['validation'] === '0' && $config['type'] == 'String'){
							$validation = true;
						}
					} else {
						$validation = call_user_func_array(array($this->Module, $this->Module->configTypes[$config['type']]['validation']), array($this->request->data['Event']['config'][$configName]));
					}
					if ($validation !== true) {
						$fail = ucfirst($configName) . ': ' . $validation;
					} else {
						if (isset($config['regex']) && !empty($config['regex'])) {
							$fail = preg_match($config['regex'], $this->request->data['Event']['config'][$configName]) ? false : ucfirst($configName) . ': ' . 'Invalid setting' . ($config['errorMessage'] ? ' - ' . $config['errorMessage'] : '');
							if (!empty($fail)) {
								$modulePayload['config'][$configName] = $this->request->data['Event']['config'][$configName];
							}
						} else {
							$modulePayload['config'][$configName] = $this->request->data['Event']['config'][$configName];
						}
					}
				}
			}
			if (!$fail) {
				if (!empty($module['mispattributes']['inputSource'])) {
					if (!isset($this->request->data['Event']['source'])) {
						if (in_array('paste', $module['mispattributes']['inputSource'])) $this->request->data['Event']['source'] = '0';
						else $this->request->data['Event']['source'] = '1';
					}
					if ($this->request->data['Event']['source'] == '1') {
						if (isset($this->request->data['Event']['data'])) {
							$modulePayload['data'] = base64_decode($this->request->data['Event']['data']);
						}
						else if (!isset($this->request->data['Event']['fileupload']) || empty($this->request->data['Event']['fileupload'])) {
							$fail = 'Invalid file upload.';
						} else {
							$fileupload = $this->request->data['Event']['fileupload'];
							$tmpfile = new File($fileupload['tmp_name']);
							if ((isset($fileupload['error']) && $fileupload['error'] == 0) || (!empty($fileupload['tmp_name']) && $fileupload['tmp_name'] != 'none') && is_uploaded_file($tmpfile->path)) {
								$filename = basename($fileupload['name']);
								App::uses('FileAccessTool', 'Tools');
								$modulePayload['data'] = (new FileAccessTool())->readFromFile($fileupload['tmp_name'], $fileupload['size']);
							} else {
								$fail = 'Invalid file upload.';
							}
						}
					} else {
						$modulePayload['data'] = $this->request->data['Event']['paste'];
					}
				} else {
					$modulePayload['data'] = '';
				}
				if (!$fail) {
					$modulePayload['data'] = base64_encode($modulePayload['data']);
					if (!empty($filename)) {
						$modulePayload['filename'] = $filename;
					}
					$result = $this->Module->queryModuleServer('/query', json_encode($modulePayload, true), false, $moduleFamily = 'Import');
					if (!$result) throw new Exception('Import service not reachable.');
					if (isset($result['error'])) $this->Session->setFlash($result['error']);
					if (!is_array($result)) throw new Exception($result);
					$resultArray = $this->Event->handleModuleResult($result, $eventId);
					if ($this->_isRest()) {
						return $this->__pushFreetext(
							$resultArray,
							$eventId,
							false,
							false,
							false
						);
					}
					if (isset($result['comment'])) {
						$importComment = $result['comment'];
					}
					else {
						$importComment = 'Enriched via the ' . $module['name'] . ' module';
					}
					$typeCategoryMapping = array();
					foreach ($this->Event->Attribute->categoryDefinitions as $k => $cat) {
						foreach ($cat['types'] as $type) {
							$typeCategoryMapping[$type][$k] = $k;
						}
					}
					foreach ($resultArray as $key => $result) {
						$options = array(
								'conditions' => array('OR' => array('Attribute.value1' => $result['value'], 'Attribute.value2' => $result['value'])),
								'fields' => array('Attribute.type', 'Attribute.category', 'Attribute.value', 'Attribute.comment'),
								'order' => false
						);
						$resultArray[$key]['related'] = $this->Event->Attribute->fetchAttributes($this->Auth->user(), $options);
					}
					$distributions = $this->Event->Attribute->distributionLevels;
					$sgs = $this->Event->SharingGroup->fetchAllAuthorised($this->Auth->user(), 'name',  1);
					if (empty($sgs)) {
						unset($distributions[4]);
					}
					$this->set('distributions', $distributions);
					$this->set('sgs', $sgs);
					$this->set('event', array('Event' => array('id' => $eventId)));
					$this->set('resultArray', $resultArray);
					$this->set('typeList', array_keys($this->Event->Attribute->typeDefinitions));
					$this->set('defaultCategories', $this->Event->Attribute->defaultCategories);
					$this->set('typeCategoryMapping', $typeCategoryMapping);
					$this->set('title', 'Import Results');
					$this->set('importComment', $importComment);
					$this->render('resolved_attributes');
				}
			}
			$this->Session->setFlash($fail);
		}
		$this->set('configTypes', $this->Module->configTypes);
		$this->set('module', $module);
		$this->set('eventId', $eventId);
	}

	public function exportModule($module, $id) {
		$result = $this->Event->export($this->Auth->user(), $module, array('eventid' => $id));
		$this->response->body(base64_decode($result['data']));
		$this->response->type($result['response']);
		$this->response->download('misp.event.' . $id . '.' . $module . '.export.' . $result['extension']);
		return $this->response;
	}

	public function toggleCorrelation($id) {
		if (!$this->_isSiteAdmin() && Configure.read('MISP.allow_disabling_correlation')) {
			throw new MethodNotAllowedException('Disabling the correlation is not permitted on this instance.');
		}
		$this->Event->id = $id;
		if (!$this->Event->exists()) {
			throw new NotFoundException('Invalid Event.');
		}
		if (!$this->Auth->user('Role')['perm_modify']) {
			throw new MethodNotAllowedException('You don\'t have permission to do that.');
		}
		$conditions = array('Event.id' => $id);
		if (!$this->_isSiteAdmin()) {
			$conditions['Event.orgc_id'] = $this->Auth->user('org_id');
		}
		$event = $this->Event->find('first', array(
			'conditions' => $conditions,
			'recursive' => -1
		));
		if (empty($event)) {
			throw new NotFoundException('Invalid Event.');
		}
		if (!$this->Auth->user('Role')['perm_modify_org'] && $this->Auth->user('id') != $event['Event']['user_id']) {
			throw new MethodNotAllowedException('You don\'t have permission to do that.');
		}
		if ($this->request->is('post')) {
			if ($event['Event']['disable_correlation']) {
				$event['Event']['disable_correlation'] = 0;
				$this->Event->save($event);
				$attributes = $this->Event->Attribute->find('all', array(
					'conditions' => array('Attribute.event_id' => $id),
					'recursive' => -1
				));
				foreach ($attributes as $attribute) {
					$this->Event->Attribute->__afterSaveCorrelation($attribute['Attribute'], false, $event);
				}
			} else {
				$event['Event']['disable_correlation'] = 1;
				$this->Event->save($event);
				$this->Event->Attribute->purgeCorrelations($id);
			}
			if ($this->_isRest()) {
				return $this->RestResponse->saveSuccessResponse('events', 'toggleCorrelation', $id, false, 'Correlation ' . ($event['Event']['disable_correlation'] ? 'disabled' : 'enabled') . '.');
			} else {
				$this->Session->setFlash('Correlation ' . ($event['Event']['disable_correlation'] ? 'disabled' : 'enabled') . '.');
				$this->redirect(array('controller' => 'events', 'action' => 'view', $id));
			}
		} else {
			$this->set('event', $event);
			$this->render('ajax/toggle_correlation');
		}
	}

	public function checkPublishedStatus($id) {
		$event = $this->Event->fetchEvent($this->Auth->user(), array('metadata' => 1, 'eventid' => $id));
		if (empty($event)) {
			throw new NotFoundException('Invalid event');
		}
		return new CakeResponse(array('body'=> h($event[0]['Event']['published']), 'status'=>200, 'type' => 'txt'));
	}

	public function pushEventToZMQ($id) {
		if ($this->request->is('Post')) {
			if (Configure::read('Plugin.ZeroMQ_enable')) {
				$pubSubTool = $this->Event->getPubSubTool();
				$event = $this->Event->fetchEvent($this->Auth->user(), array('eventid' => $id));
				if (!empty($event)) {
					$pubSubTool->publishEvent($event[0]);
					$message = 'Event published to ZMQ';
				} else {
					$message = 'Invalid event.';
				}
			} else {
				$message = 'ZMQ event publishing not enabled.';
			}
		} else {
			$message = 'This functionality is only available via POST requests';
		}
		if ($this->_isRest()) {
			return $this->RestResponse->saveSuccessResponse('Events', 'pushEventToZMQ', $id, $this->response->type(), $message);
		} else {
			$this->Session->setFlash($message);
			$this->redirect($this->referer());
		}
	}
}
