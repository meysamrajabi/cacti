<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2016 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

function repopulate_poller_cache() {
	$poller_data    = db_fetch_assoc('SELECT ' . SQL_NO_CACHE . ' * FROM data_local');
	$poller_items   = array();
	$local_data_ids = array();
	$i = 0;

	if (sizeof($poller_data)) {
		foreach ($poller_data as $data) {
			$poller_items     = array_merge($poller_items, update_poller_cache($data));
			$local_data_ids[] = $data['id'];
			$i++;

			if ($i > 500) {
				$i = 0;
				poller_update_poller_cache_from_buffer($local_data_ids, $poller_items);
				$local_data_ids = array();
				$poller_items   = array();
			}
		}

		if ($i > 0) {
			poller_update_poller_cache_from_buffer($local_data_ids, $poller_items);
		}
	}

	$poller_ids = array_rekey(db_fetch_assoc('SELECT DISTINCT poller_id FROM poller_item'), 'poller_id', 'poller_id');
	if (sizeof($poller_ids)) {
	foreach($poller_ids as $poller_id) {
		api_data_source_cache_crc_update($poller_id);
	}
	}
}

function update_poller_cache_from_query($host_id, $data_query_id) {
	$poller_data = db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . ' * FROM data_local 
		WHERE host_id = ?  AND snmp_query_id = ?', array($host_id, $data_query_id));

	$i = 0;
	$poller_items = $local_data_ids = array();

	if (sizeof($poller_data)) {
		foreach ($poller_data as $data) {
			$poller_items     = array_merge($poller_items, update_poller_cache($data));
			$local_data_ids[] = $data['id'];
			$i++;

			if ($i > 500) {
				$i = 0;
				poller_update_poller_cache_from_buffer($local_data_ids, $poller_items);
				$local_data_ids = array();
				$poller_items   = array();
			}
		}

		if ($i > 0) {
			poller_update_poller_cache_from_buffer($local_data_ids, $poller_items);
		}
	}

	$poller_ids = array_rekey(db_fetch_assoc_prepared('SELECT DISTINCT poller_id FROM poller_item WHERE host_id = ?', array($host_id)), 'poller_id', 'poller_id');
	if (sizeof($poller_ids)) {
	foreach($poller_ids as $poller_id) {
		api_data_source_cache_crc_update($poller_id);
	}
	}
}

function update_poller_cache($data_source, $commit = false) {
	global $config;

	include_once($config['library_path'] . '/data_query.php');
	include_once($config['library_path'] . '/api_poller.php');

	if (!is_array($data_source)) {
		$data_source = db_fetch_row_prepared('SELECT ' . SQL_NO_CACHE . ' * FROM data_local WHERE id = ?', array($data_source));
	}

	$poller_items = array();

	$data_input = db_fetch_row_prepared('SELECT ' . SQL_NO_CACHE . '
		di.id, di.type_id, dtd.id AS data_template_data_id,
		dtd.data_template_id, dtd.active, dtd.rrd_step
		FROM data_template_data AS dtd
		INNER JOIN data_input AS di
		ON dtd.data_input_id=di.id
		WHERE dtd.local_data_id = ?', array($data_source['id']));

	/* we have to perform some additional sql queries if this is a 'query' */
	if (($data_input['type_id'] == DATA_INPUT_TYPE_SNMP_QUERY) ||
		($data_input['type_id'] == DATA_INPUT_TYPE_SCRIPT_QUERY) ||
		($data_input['type_id'] == DATA_INPUT_TYPE_QUERY_SCRIPT_SERVER)){
		$field = data_query_field_list($data_input['data_template_data_id']);

		$params = array();
		if (strlen($field['output_type'])) {
			$output_type_sql = ' AND snmp_query_graph_rrd.snmp_query_graph_id = ?';
			$params[] = $field['output_type'];
		}else{
			$output_type_sql = '';
		}
		$params[] = $data_input['data_template_id'];
		$params[] = $data_source['id'];

		$outputs = db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . "
			snmp_query_graph_rrd.snmp_field_name,
			data_template_rrd.id as data_template_rrd_id
			FROM (snmp_query_graph_rrd,data_template_rrd FORCE INDEX (local_data_id))
			WHERE snmp_query_graph_rrd.data_template_rrd_id = data_template_rrd.local_data_template_rrd_id
			$output_type_sql
			AND snmp_query_graph_rrd.data_template_id = ?
			AND data_template_rrd.local_data_id = ?
			ORDER BY data_template_rrd.id", $params);
	}

	if ($data_input['active'] == 'on') {
		if (($data_input['type_id'] == DATA_INPUT_TYPE_SCRIPT) || ($data_input['type_id'] == DATA_INPUT_TYPE_PHP_SCRIPT_SERVER)) { /* script */
			/* fall back to non-script server actions if the user is running a version of php older than 4.3 */
			if (($data_input['type_id'] == DATA_INPUT_TYPE_PHP_SCRIPT_SERVER) && (function_exists('proc_open'))) {
				$action = POLLER_ACTION_SCRIPT_PHP;
				$script_path = get_full_script_path($data_source['id']);
			}else if (($data_input['type_id'] == DATA_INPUT_TYPE_PHP_SCRIPT_SERVER) && (!function_exists('proc_open'))) {
				$action = POLLER_ACTION_SCRIPT;
				$script_path = read_config_option('path_php_binary') . ' -q ' . get_full_script_path($data_source['id']);
			}else{
				$action = POLLER_ACTION_SCRIPT;
				$script_path = get_full_script_path($data_source['id']);
			}

			$num_output_fields = sizeof(db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . ' id 
				FROM data_input_fields 
				WHERE data_input_id = ? 
				AND input_output="out" 
				AND update_rra="on"', 
				array($data_input['id'])));

			if ($num_output_fields == 1) {
				$data_template_rrd_id = db_fetch_cell_prepared('SELECT ' . SQL_NO_CACHE . ' id FROM data_template_rrd WHERE local_data_id = ?', array($data_source['id']));
				$data_source_item_name = get_data_source_item_name($data_template_rrd_id);
			}else{
				$data_source_item_name = '';
			}

			$poller_items[] = api_poller_cache_item_add($data_source['host_id'], array(), $data_source['id'], $data_input['rrd_step'], $action, $data_source_item_name, 1, $script_path);
		}else if ($data_input['type_id'] == DATA_INPUT_TYPE_SNMP) { /* snmp */
			/* get the host override fields */
			$data_template_id = db_fetch_cell_prepared('SELECT ' . SQL_NO_CACHE . ' data_template_id FROM data_template_data WHERE local_data_id = ?', array($data_source['id']));

			/* get host fields first */
			$host_fields = array_rekey(
				db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . ' dif.type_code, did.value
					FROM data_input_fields AS dif
					LEFT JOIN data_input_data AS did
					ON dif.id=did.data_input_field_id
					WHERE (type_code LIKE "snmp_%" OR type_code IN("hostname","host_id"))
					AND did.data_template_data_id = ?
					AND did.value != ""', array($data_input['data_template_data_id'])), 
				'type_code', 'value'
			);

			$data_template_fields = array_rekey(
				db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . ' dif.type_code, did.value
					FROM data_input_fields AS dif
					LEFT JOIN data_input_data AS did
					ON dif.id=did.data_input_field_id
					WHERE (type_code LIKE "snmp_%" OR type_code="hostname")
					AND did.data_template_data_id = ?
					AND data_template_data_id = ?
					AND did.value != ""', array($data_template_id, $data_template_id)), 
				'type_code', 'value'
			);

			if (sizeof($host_fields)) {
				if (sizeof($data_template_fields)) {
				foreach($data_template_fields as $key => $value) {
					if (!isset($host_fields[$key])) {
						$host_fields[$key] = $value;
					}
				}
				}
			} elseif (sizeof($data_template_fields)) {
				$host_fields = $data_template_fields;
			}

			$data_template_rrd_id = db_fetch_cell_prepared('SELECT ' . SQL_NO_CACHE . ' id FROM data_template_rrd WHERE local_data_id = ?', array($data_source['id']));

			$poller_items[] = api_poller_cache_item_add($data_source['host_id'], $host_fields, $data_source['id'], $data_input['rrd_step'], 0, get_data_source_item_name($data_template_rrd_id), 1, (isset($host_fields['snmp_oid']) ? $host_fields['snmp_oid'] : ''));
		}else if ($data_input['type_id'] == DATA_INPUT_TYPE_SNMP_QUERY) { /* snmp query */
			$snmp_queries = get_data_query_array($data_source['snmp_query_id']);

			/* get the host override fields */
			$data_template_id = db_fetch_cell_prepared('SELECT ' . SQL_NO_CACHE . ' data_template_id FROM data_template_data WHERE local_data_id = ?', array($data_source['id']));

			/* get host fields first */
			$host_fields = array_rekey(
				db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . ' dif.type_code, did.value
					FROM data_input_fields AS dif
					LEFT JOIN data_input_data AS did
					ON dif.id=did.data_input_field_id
					WHERE (type_code LIKE "snmp_%" OR type_code="hostname")
					AND did.data_template_data_id = ?
					AND did.value != ""', array($data_input['data_template_data_id'])), 
				'type_code', 'value'
			);

			$data_template_fields = array_rekey(
				db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . ' dif.type_code, did.value
					FROM data_input_fields AS dif
					LEFT JOIN data_input_data AS did
					ON dif.id=did.data_input_field_id
					WHERE (type_code LIKE "snmp_%" OR type_code="hostname")
					AND did.data_template_data_id = ?
					AND data_template_data_id = ?
					AND did.value != ""', array($data_template_id, $data_template_id)), 
				'type_code', 'value'
			);

			if (sizeof($host_fields)) {
				if (sizeof($data_template_fields)) {
				foreach($data_template_fields as $key => $value) {
					if (!isset($host_fields[$key])) {
						$host_fields[$key] = $value;
					}
				}
				}
			} elseif (sizeof($data_template_fields)) {
				$host_fields = $data_template_fields;
			}

			if (sizeof($outputs) > 0) {
			foreach ($outputs as $output) {
				if (isset($snmp_queries['fields']{$output['snmp_field_name']}['oid'])) {
					$oid = $snmp_queries['fields']{$output['snmp_field_name']}['oid'] . '.' . $data_source['snmp_index'];

					if (isset($snmp_queries['fields']{$output['snmp_field_name']}['oid_suffix'])) {
						$oid .= '.' . $snmp_queries['fields']{$output['snmp_field_name']}['oid_suffix'];
					}
				}

				if (!empty($oid)) {
					$poller_items[] = api_poller_cache_item_add($data_source['host_id'], $host_fields, $data_source['id'], $data_input['rrd_step'], 0, get_data_source_item_name($output['data_template_rrd_id']), sizeof($outputs), $oid);
				}
			}
			}
		}else if (($data_input['type_id'] == DATA_INPUT_TYPE_SCRIPT_QUERY) || ($data_input['type_id'] == DATA_INPUT_TYPE_QUERY_SCRIPT_SERVER)) { /* script query */
			$script_queries = get_data_query_array($data_source['snmp_query_id']);

			/* get the host override fields */
			$data_template_id = db_fetch_cell_prepared('SELECT ' . SQL_NO_CACHE . ' data_template_id FROM data_template_data WHERE local_data_id = ?', array($data_source['id']));

			/* get host fields first */
			$host_fields = array_rekey(
				db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . ' dif.type_code, did.value
					FROM data_input_fields AS dif
					LEFT JOIN data_input_data AS did
					ON dif.id=did.data_input_field_id
					WHERE (type_code LIKE "snmp_%" OR type_code="hostname")
					AND did.data_template_data_id = ?
					AND did.value != ""', array($data_input['data_template_data_id'])), 
				'type_code', 'value'
			);

			$data_template_fields = array_rekey(
				db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . ' dif.type_code, did.value
					FROM data_input_fields AS dif
					LEFT JOIN data_input_data AS did
					ON dif.id=did.data_input_field_id
					WHERE (type_code LIKE "snmp_%" OR type_code="hostname")
					AND data_template_data_id = ?
					AND did.data_template_data_id = ?
					AND did.value != ""', array($data_template_id, $data_template_id)), 
				'type_code', 'value'
			);

			if (sizeof($host_fields)) {
				if (sizeof($data_template_fields)) {
				foreach($data_template_fields as $key => $value) {
					if (!isset($host_fields[$key])) {
						$host_fields[$key] = $value;
					}
				}
				}
			} elseif (sizeof($data_template_fields)) {
				$host_fields = $data_template_fields;
			}

			if (sizeof($outputs) > 0) {
				foreach ($outputs as $output) {
					if (isset($script_queries['fields']{$output['snmp_field_name']}['query_name'])) {
						$identifier = $script_queries['fields']{$output['snmp_field_name']}['query_name'];

						/* fall back to non-script server actions if the user is running a version of php older than 4.3 */
						if (($data_input['type_id'] == DATA_INPUT_TYPE_QUERY_SCRIPT_SERVER) && (function_exists('proc_open'))) {
							$action = POLLER_ACTION_SCRIPT_PHP;
							$script_path = get_script_query_path((isset($script_queries['arg_prepend']) ? $script_queries['arg_prepend'] : '') . ' ' . $script_queries['arg_get'] . ' ' . $identifier . ' ' . escapeshellarg($data_source['snmp_index']), $script_queries['script_path'] . ' ' . $script_queries['script_function'], $data_source['host_id']);
						}else if (($data_input['type_id'] == DATA_INPUT_TYPE_QUERY_SCRIPT_SERVER) && (!function_exists('proc_open'))) {
							$action = POLLER_ACTION_SCRIPT;
							$script_path = read_config_option('path_php_binary') . ' -q ' . get_script_query_path((isset($script_queries['arg_prepend']) ? $script_queries['arg_prepend'] : '') . ' ' . $script_queries['arg_get'] . ' ' . $identifier . ' ' . $data_source['snmp_index'], $script_queries['script_path'], $data_source['host_id']);
						}else{
							$action = POLLER_ACTION_SCRIPT;
							$script_path = get_script_query_path((isset($script_queries['arg_prepend']) ? $script_queries['arg_prepend'] : '') . ' ' . $script_queries['arg_get'] . ' ' . $identifier . ' ' . escapeshellarg($data_source['snmp_index']), $script_queries['script_path'], $data_source['host_id']);
						}
					}

					if (isset($script_path)) {
						$poller_items[] = api_poller_cache_item_add($data_source['host_id'], $host_fields, $data_source['id'], $data_input['rrd_step'], $action, get_data_source_item_name($output['data_template_rrd_id']), sizeof($outputs), $script_path);
					}
				}
			}
		}
	}

	if ($commit) {
		poller_update_poller_cache_from_buffer((array)$data_source['id'], $poller_items);
	} else {
		return $poller_items;
	}
}

function push_out_data_input_method($data_input_id) {
	$data_sources = db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . ' * FROM data_local
		WHERE id IN (
			SELECT DISTINCT local_data_id
			FROM data_template_data
			WHERE data_input_id = ?
			AND local_data_id>0
		)', array($data_input_id));

	$poller_items = array();
	$_my_local_data_ids = array();

	if (sizeof($data_sources)) {
		foreach($data_sources as $data_source) {
			$_my_local_data_ids[] = $data_source['id'];

			$poller_items = array_merge($poller_items, update_poller_cache($data_source));
		}

		if (sizeof($_my_local_data_ids)) {
			poller_update_poller_cache_from_buffer($_my_local_data_ids, $poller_items);
		}
	}
}

/** mass update of poller cache - can run in parallel to poller
 * @param array/int $local_data_ids - either a scalar (all ids) or an array of data source to act on
 * @param array $poller_items - the new items for poller cache
 */
function poller_update_poller_cache_from_buffer($local_data_ids, &$poller_items) {
	/* set all fields present value to 0, to mark the outliers when we are all done */
	$ids = array();
	if (sizeof($local_data_ids)) {
		$count = 0;
		foreach($local_data_ids as $id) {
			if ($count == 0) {
				$ids = $id;
			} else {
				$ids .= ', ' . $id;
			}
			$count++;
		}

		if ($ids != '') {
			db_execute("UPDATE poller_item SET present=0 WHERE local_data_id IN ($ids)");
		}
	} else {
		/* don't mark anything in case we have no $local_data_ids => 
		 *this would flush the whole table at bottom of this function */
	}

	/* setup the database call */
	$sql_prefix   = 'INSERT INTO poller_item (local_data_id, poller_id, host_id, action, hostname, ' .
		'snmp_community, snmp_version, snmp_timeout, snmp_username, snmp_password, ' .
		'snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_context, snmp_engine_id, ' .
		'snmp_port, rrd_name, rrd_path, rrd_num, rrd_step, rrd_next_step, arg1, arg2, arg3, present) ' .
		'VALUES';

	$sql_suffix   = ' ON DUPLICATE KEY UPDATE poller_id=VALUES(poller_id), host_id=VALUES(host_id), action=VALUES(action), hostname=VALUES(hostname), ' .
		'snmp_community=VALUES(snmp_community), snmp_version=VALUES(snmp_version), snmp_timeout=VALUES(snmp_timeout), ' .
		'snmp_username=VALUES(snmp_username), snmp_password=VALUES(snmp_password), snmp_auth_protocol=VALUES(snmp_auth_protocol), ' .
		'snmp_priv_passphrase=VALUES(snmp_priv_passphrase), snmp_priv_protocol=VALUES(snmp_priv_protocol), ' .
		'snmp_context=VALUES(snmp_context), snmp_engine_id=VALUES(snmp_engine_id), snmp_port=VALUES(snmp_port), ' .
		'rrd_path=VALUES(rrd_path), rrd_num=VALUES(rrd_num), ' .
		'rrd_step=VALUES(rrd_step), rrd_next_step=VALUES(rrd_next_step), arg1=VALUES(arg1), arg2=VALUES(arg2), ' .
		'arg3=VALUES(arg3), present=VALUES(present)';

	/* use a reasonable insert buffer, the default is 1MByte */
	$max_packet   = 256000;

	/* setup somme defaults */
	$overhead     = strlen($sql_prefix) + strlen($sql_suffix);
	$buf_len      = 0;
	$buf_count    = 0;
	$buffer       = '';

	if (sizeof($poller_items)) {
	foreach($poller_items AS $record) {
		/* take care of invalid entries */
		if (strlen($record) == 0) continue;

		if ($buf_count == 0) {
			$delim = ' ';
		} else {
			$delim = ', ';
		}

		$buffer .= $delim . $record;

		$buf_len += strlen($record);

		if (($overhead + $buf_len) > ($max_packet - 1024)) {
			db_execute($sql_prefix . $buffer . $sql_suffix);

			$buffer    = '';
			$buf_len   = 0;
			$buf_count = 0;
		} else {
			$buf_count++;
		}
	}
	}

	if ($buf_count > 0) {
		db_execute($sql_prefix . $buffer . $sql_suffix);
	}

	/* remove stale records FROM the poller cache */
	if (sizeof($ids)) {
		db_execute("DELETE FROM poller_item WHERE present=0 AND local_data_id IN ($ids)");
	}else{
		/* only handle explicitely given local_data_ids */
	}
}

/** for a given data template, update all input data and the poller cache
 * @param int $host_id - id of host, if any
 * @param int $local_data_id - id of a single data source, if any
 * @param int $data_template_id - id of data template
 * works on table data_input_data and poller cache
 */
function push_out_host($host_id, $local_data_id = 0, $data_template_id = 0) {
	/* ok here's the deal: first we need to find every data source that uses this host.
	then we go through each of those data sources, finding each one using a data input method
	with "special fields". if we find one, fill it will the data here FROM this host */
	/* setup the poller items array */
	$poller_items   = array();
	$local_data_ids = array();
	$hosts          = array();
	$sql_where      = '';

	/* setup the sql where, and if using a host, get it's host information */
	if ($host_id != 0) {
		/* get all information about this host so we can write it to the data source */
		$hosts[$host_id] = db_fetch_row_prepared('SELECT ' . SQL_NO_CACHE . ' id AS host_id, host.* FROM host WHERE id = ?', array($host_id));

		$sql_where .= ' AND dl.host_id=' . $host_id;
	}

	/* sql WHERE for local_data_id */
	if ($local_data_id != 0) {
		$sql_where .= ' AND dl.id=' . $local_data_id;
	}

	/* sql WHERE for data_template_id */
	if ($data_template_id != 0) {
		$sql_where .= ' AND dtd.data_template_id=' . $data_template_id;
	}

	$data_sources = db_fetch_assoc('SELECT ' . SQL_NO_CACHE . " dtd.id, dtd.data_input_id, dtd.local_data_id,
		dtd.local_data_template_data_id, dl.host_id, dl.snmp_query_id, dl.snmp_index
		FROM data_local AS dl
		INNER JOIN data_template_data AS dtd
		ON dl.id=dtd.local_data_id
		WHERE dtd.data_input_id>0
		$sql_where");

	/* loop through each matching data source */
	if (sizeof($data_sources)) {
	foreach ($data_sources as $data_source) {
		/* set the host information */
		if (!isset($hosts[$data_source['host_id']])) {
			$hosts[$data_source['host_id']] = db_fetch_row_prepared('SELECT * FROM host WHERE id = ?', array($data_source['host_id']));
		}
		$host = $hosts[$data_source['host_id']];

		/* get field information FROM the data template */
		if (!isset($template_fields{$data_source['local_data_template_data_id']})) {
			$template_fields{$data_source['local_data_template_data_id']} = db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . '
				did.value, did.t_value, dif.id, dif.type_code
				FROM data_input_fields AS dif
				LEFT JOIN data_input_data AS did
				ON dif.id=did.data_input_field_id
				WHERE dif.data_input_id = ?
				AND did.data_template_data_id = ?
				AND (did.t_value="" OR did.t_value is null)
				AND dif.input_output="in"', 
				array($data_source['data_input_id'], $data_source['local_data_template_data_id']));
		}

		reset($template_fields{$data_source['local_data_template_data_id']});

		/* loop through each field contained in the data template and push out a host value if:
		 - the field is a valid "host field"
		 - the value of the field is empty
		 - the field is set to 'templated' */
		if (sizeof($template_fields{$data_source['local_data_template_data_id']})) {
		foreach ($template_fields{$data_source['local_data_template_data_id']} as $template_field) {
			if ((preg_match('/^' . VALID_HOST_FIELDS . '$/i', $template_field['type_code'])) && ($template_field['value'] == '') && ($template_field['t_value'] == '')) {
				// handle special case type_code
				if ($template_field['type_code'] == 'host_id') $template_field['type_code'] = 'id';

				db_execute_prepared('REPLACE INTO data_input_data 
					(data_input_field_id, data_template_data_id, value) 
					VALUES (?, ?, ?)', 
					array($template_field['id'], $data_source['id'], $host[$template_field['type_code']]));
			}
		}
		}

		/* flag an update to the poller cache as well */
		$local_data_ids[] = $data_source['local_data_id'];

		/* create a new compatible structure */
		$data = $data_source;
		$data['id'] = $data['local_data_id'];

		$poller_items = array_merge($poller_items, update_poller_cache($data));
	}
	}

	if (sizeof($local_data_ids)) {
		poller_update_poller_cache_from_buffer($local_data_ids, $poller_items);
	}

	$poller_id = db_fetch_cell_prepared('SELECT poller_id FROM host WHERE id = ?', array($host_id));

	api_data_source_cache_crc_update($poller_id);
}

function utilities_get_mysql_recommendations() {
	// MySQL Important Variables
	$variables = array_rekey(db_fetch_assoc('SHOW GLOBAL VARIABLES'), 'Variable_name', 'Value');

	$memInfo = utilities_get_system_memory();

	$recommendations = array(
		'version' => array(
			'value' => '5.6',
			'measure' => 'gt',
			'comment' => __('MySQL 5.6 is great release, and a very good version to choose.  
				Other choices today include MariaDB which is very popular and addresses some issues
				with the C API that negatively impacts spine in MySQL 5.5, and for some reason
				Oracle has chosen not to fix in MySQL 5.5.  So, avoid MySQL 5.5 at all cost.')
			)
	);

	if ($variables['version'] < '5.6') {
		$recommendations += array(
			'collation_server' => array(
				'value' => 'utf8_general_ci',
				'class' => 'warning',
				'measure' => 'equal',
				'comment' => __('When using Cacti with languages other than English, it is important to use
					the utf8_general_ci collation type as some characters take more than a single byte.  
					If you are first just now installing Cacti, stop, make the changes and start over again.
					If your Cacti has been running and is in production, see the internet for instructions
					on converting your databases and tables if you plan on supporting other languages.')
				),
			'character_set_client' => array(
				'value' => 'utf8',
				'class' => 'warning',
				'measure' => 'equal',
				'comment' => __('When using Cacti with languages other than English, it is important ot use
					the utf8 character set as some characters take more than a single byte.
					If you are first just now installing Cacti, stop, make the changes and start over again.
					If your Cacti has been running and is in production, see the internet for instructions
					on converting your databases and tables if you plan on supporting other languages.')
				)
		);
	}else{
		$recommendations += array(
			'collation_server' => array(
				'value' => 'utf8mb4_col',
				'class' => 'warning',
				'measure' => 'equal',
				'comment' => __('When using Cacti with languages other than English, it is important to use
					the utf8mb4_col collation type as some characters take more than a single byte.')
				),
			'character_set_client' => array(
				'value' => 'utf8mb4',
				'class' => 'warning',
				'measure' => 'equal',
				'comment' => __('When using Cacti with languages other than English, it is important ot use
					the utf8mb4 character set as some characters take more than a single byte.')
				)
		);
	}

	$recommendations += array(
		'max_connections' => array(
			'value'   => '100', 
			'measure' => 'gt', 
			'comment' => __('Depending on the number of logins and use of spine data collector, 
				MySQL will need many connections.  The calculation for spine is:
				total_connections = total_processes * (total_threads + script_servers + 1), then you
				must leave headroom for user connections, which will change depending on the number of
				concurrent login accounts.')
			),
		'max_heap_table_size' => array(
			'value'   => '5',
			'measure' => 'pmem',
			'comment' => __('If using the Cacti Performance Booster and choosing a memory storage engine,
				you have to be careful to flush your Performance Booster buffer before the system runs
				out of memory table space.  This is done two ways, first reducing the size of your output
				column to just the right size.  This column is in the tables poller_output, 
				and poller_output_boost.  The second thing you can do is allocate more memory to memory
				tables.  We have arbitrarily choosen a recommended value of 10% of system memory, but 
				if you are using SSD disk drives, or have a smaller system, you may ignore this recommendation
				or choose a different storage engine.  You may see the expected consumption of the 
				Performance Booster tables under Console -> System Utilities -> View Boost Status.')
			),
		'table_cache' => array(
			'value'   => '200',
			'measure' => 'gt',
			'comment' => __('Keeping the table cache larger means less file open/close operations when
				using innodb_file_per_table.')
			),
		'max_allowed_packet' => array(
			'value'   => 16777216,
			'measure' => 'gt',
			'comment' => __('With Remote polling capabilities, large amounts of data 
				will be synced from the main server to the remote pollers.  
				Therefore, keep this value at or above 16M.')
			),
		'tmp_table_size' => array(
			'value'   => '64M',
			'measure' => 'gtm',
			'comment' => __('When executing subqueries, having a larger temporary table size, 
				keep those temporary tables in memory.')
			),
		'join_buffer_size' => array(
			'value'   => '64M',
			'measure' => 'gtm',
			'comment' => __('When performing joins, if they are below this size, they will 
				be kept in memory and never writen to a temporary file.')
			),
		'innodb_file_per_table' => array(
			'value'   => 'ON',
			'measure' => 'equal',
			'comment' => __('When using InnoDB storage it is important to keep your table spaces
				separate.  This makes managing the tables simpler for long time users of MySQL.
				If you are running with this currently off, you can migrate to the per file storage
				by enabling the feature, and then running an alter statement on all InnoDB tables.')
			),
		'innodb_buffer_pool_size' => array(
			'value'   => '25',
			'measure' => 'pmem',
			'comment' => __('InnoDB will hold as much tables and indexes in system memory as is possible.
				Therefore, you should make the innodb_buffer_pool large enough to hold as much
				of the tables and index in memory.  Checking the size of the /var/lib/mysql/cacti
				directory will help in determining this value.  We are recommending 25% of your systems
				total memory, but your requirements will vary depending on your systems size.')
			),
		'innodb_doublewrite' => array(
			'value'   => 'OFF',
			'measure' => 'equal',
			'comment' => __('With modern SSD type storage, this operation actually degrades the disk
				more rapidly and adds a 50% overhead on all write operations.')
			),
		'innodb_additional_mem_pool_size' => array(
			'value'   => '80M',
			'measure' => 'gtm',
			'comment' => __('This is where metadata is stored. If you had a lot of tables, it would be useful to increase this.')
			),
		'innodb_lock_wait_timeout' => array(
			'value'   => '50',
			'measure' => 'gt',
			'comment' => __('Rogue queries should not for the database to go offline to others.  Kill these
				queries before they kill your system.')
			)
	);

	if ($variables['version'] < '5.6') {
		$recommendations += array(
			'innodb_flush_log_at_trx_commit' => array(
				'value'   => '2',
				'measure' => 'equal',
				'comment' => __('Setting this value to 2 means that you will flush all transactions every
				second rather than at commit.  This allows MySQL to perform writing less often.')
			),
			'innodb_file_io_threads' => array(
				'value'   => '16',
				'measure' => 'gt',
				'comment' => __('With modern SSD type storage, having multiple io threads is advantagious for
					applications with high io characteristics.')
				)
		);
	}else{
		$recommendations += array(
			'innodb_flush_log_at_timeout' => array(
				'value'   => '3',
				'measure'  => 'gt',
				'comment'  => __('As of MySQL 5.6, the you can control how often MySQL flushes transactions to disk.  The default is 1 second, but in high I/O systems setting to a value greater than 1 can allow disk I/O to be more sequential'),
				),
			'innodb_read_io_threads' => array(
				'value'   => '32',
				'measure' => 'gt',
				'comment' => __('With modern SSD type storage, having multiple read io threads is advantagious for
					applications with high io characteristics.')
				),
			'innodb_write_io_threads' => array(
				'value'   => '16',
				'measure' => 'gt',
				'comment' => __('With modern SSD type storage, having multiple write io threads is advantagious for
					applications with high io characteristics.')
				),
			'innodb_buffer_pool_instances' => array(
				'value' => '16',
				'measure' => 'present',
				'comment' => __('MySQL will divide the innodb_buffer_pool into memory regions to improve performance.
					The max value is 64.  When your innodb_buffer_pool is less than 1GB, you should use the pool size
					divided by 128MB.  Continue to use this equation upto the max of 64.')
				)
		);
	}

	html_header(array(__('MySQL Tuning') . ' (/etc/my.cnf) - [ <a class="linkOverDark" href="https://dev.mysql.com/doc/refman/' . substr($variables['version'],0,3) . '/en/server-system-variables.html">' . __('Documentation') . '</a> ] ' . __('Note: Many changes below require a database restart')), 2);

	form_alternate_row();
	print "<td colspan='2' style='text-align:left;padding:0px'>";
	print "<table id='mysql' class='cactiTable' style='width:100%'>\n";
	print "<thead>\n";
	print "<tr class='tableHeader'>\n";
	print "  <th class='tableSubHeaderColumn'>Variable</th>\n";
	print "  <th class='tableSubHeaderColumn'>Current Value</th>\n";
	print "  <th class='tableSubHeaderColumn'>Recommended Value</th>\n";
	print "  <th class='tableSubHeaderColumn'>Comments</th>\n";
	print "</tr>\n";
	print "</thead>\n";

	foreach($recommendations as $name => $r) {
		if (isset($variables[$name])) {
			$class = '';

			form_alternate_row();
			switch($r['measure']) {
			case 'gtm':
				$value = trim($r['value'], 'M') * 1024 * 1024;
				if ($variables[$name] < $value) {
					if (isset($r['class']) && $r['class'] == 'warning') {
						$class = 'textWarning';
					}else{
						$class = 'textError';
					}
				}

				print "<td>" . $name . "</td>\n";
				print "<td class='$class'>" . ($variables[$name]/1024/1024) . "M</td>\n";
				print "<td>>= " . $r['value'] . "</td>\n";
				print "<td class='$class'>" . $r['comment'] . "</td>\n";

				break;
			case 'gt':
				if ($variables[$name] < $r['value']) {
					if (isset($r['class']) && $r['class'] == 'warning') {
						$class = 'textWarning';
					}else{
						$class = 'textError';
					}
				}

				print "<td>" . $name . "</td>\n";
				print "<td class='$class'>" . $variables[$name] . "</td>\n";
				print "<td>>= " . $r['value'] . "</td>\n";
				print "<td class='$class'>" . $r['comment'] . "</td>\n";

				break;
			case 'equal':
				if ($variables[$name] != $r['value']) {
					if (isset($r['class']) && $r['class'] == 'warning') {
						$class = 'textWarning';
					}else{
						$class = 'textError';
					}
				}

				print "<td>" . $name . "</td>\n";
				print "<td class='$class'>" . $variables[$name] . "</td>\n";
				print "<td>" . $r['value'] . "</td>\n";
				print "<td class='$class'>" . $r['comment'] . "</td>\n";

				break;
			case 'pmem':
				if (isset($memInfo['MemTotal'])) {
					$totalMem = $memInfo['MemTotal'];
				}else{
					$totalMem = $memInfo['TotalVisibleMemorySize'];
				}

				if ($variables[$name] < ($r['value']*$totalMem/100)) {
					if (isset($r['class']) && $r['class'] == 'warning') {
						$class = 'textWarning';
					}else{
						$class = 'textError';
					}
				}

				print "<td>" . $name . "</td>\n";
				print "<td class='$class'>" . round($variables[$name]/1024/1024,0) . "M</td>\n";
				print "<td>>=" . round($r['value']*$totalMem/100/1024/1024,0) . "M</td>\n";
				print "<td class='$class'>" . $r['comment'] . "</td>\n";

				break;
			}
			form_end_row();
		}
	}
	print "</table>\n";
	print "</td>\n";
	form_end_row();
}

function utilities_php_modules() {
	/*
	   Gather phpinfo into a string variable - This has to be done before
	   any headers are sent to the browser, as we are going to do some
	   output buffering fun
	*/

	ob_start();
	phpinfo(INFO_MODULES);
	$php_info = ob_get_contents();
	ob_end_clean();

	/* Remove nasty style sheets, links and other junk */
	$php_info = str_replace("\n", '', $php_info);
	$php_info = preg_replace('/^.*\<body\>/', '', $php_info);
	$php_info = preg_replace('/\<\/body\>.*$/', '', $php_info);
	$php_info = preg_replace('/\<a.*\>/U', '', $php_info);
	$php_info = preg_replace('/\<\/a\>/', '<hr>', $php_info);
	$php_info = preg_replace('/\<img.*\>/U', '', $php_info);
	$php_info = preg_replace('/\<\/?address\>/', '', $php_info);

	return $php_info;
}

function memory_bytes($val) {
	$val = trim($val);
	$last = strtolower($val{strlen($val)-1});
	switch($last) {
		// The 'G' modifier is available since PHP 5.1.0
		case 'g':
			$val *= 1024;
		case 'm':
			$val *= 1024;
		case 'k':
			$val *= 1024;
	}

	return $val;
}

function memory_readable($val) {
	if ($val < 1024) {
		$val_label = 'bytes';
	}elseif ($val < 1048576) {
		$val_label = 'K';
		$val /= 1024;
	}elseif ($val < 1073741824) {
		$val_label = 'M';
		$val /= 1048576;
	}else{
		$val_label = 'G';
		$val /= 1073741824;
	}

	return $val . $val_label;
}

function utilities_get_system_memory() {
	global $config;

	if ($config['cacti_server_os'] == 'win32') {
		exec('wmic os get FreePhysicalMemory', $memInfo['FreePhysicalMemory']);
		exec('wmic os get FreeSpaceInPagingFiles', $memInfo['FreeSpaceInPagingFiles']);
		exec('wmic os get FreeVirtualMemory', $memInfo['FreeVirtualMemory']);
		exec('wmic os get SizeStoredInPagingFiles', $memInfo['SizeStoredInPagingFiles']);
		exec('wmic os get TotalVirtualMemorySize', $memInfo['TotalVirtualMemorySize']);
		exec('wmic os get TotalVisibleMemorySize', $memInfo['TotalVisibleMemorySize']);
		if (sizeof($memInfo)) {
			foreach($memInfo as $key => $values) {
				$memInfo[$key] = $values[1];
			}
		}
	}else{
		$data = explode("\n", file_get_contents('/proc/meminfo'));
		foreach($data as $l) {
			if (trim($l) != '') {
				list($key, $val) = explode(':', $l);
				$val = trim($val, " kBb\r\n");
				$memInfo[$key] = round($val * 1024,0);
			}
		}
	}

	return $memInfo;
}

