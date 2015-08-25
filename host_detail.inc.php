<?php

// Lets do some initial install related stuff
if (file_exists(dirname(__FILE__)."/install.php")) {
    printmsg("DEBUG => Found install file for plugin.", 1);
    include(dirname(__FILE__)."/install.php");
}






///////////////////////////////////////////////////////////////////////
//  Function: host_detail (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = host_detail('host=test');
///////////////////////////////////////////////////////////////////////
function host_detail($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '1.03';

    printmsg("DEBUG => host_detail({$options}) called", 3);

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Return the usage summary if we need to
    if ($options['help'] or !($options['host']) ) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        $self['error'] = 'ERROR => Insufficient parameters';
        return(array(1,
<<<EOM

host_detail-v{$version}
Display detailed info about a host and it's interfaces

  Synopsis: host_detail [KEY=VALUE] ...

  Required:
    host=FQDN|IP           FQDN or IP of host

  Optional:
    format=[yaml|json]     Output in yaml or json. Default: yaml


EOM
        ));
    }

    $details = array();

    // Find the host (and domain) record from $options['host']
    list($status, $rows, $host) = ona_find_host($options['host']);
    if (!$host['id']) {
        printmsg("DEBUG => Unknown host: {$options['host']}",3);
        $self['error'] = "ERROR => Unknown host: {$options['host']}";
        return(array(2, $self['error'] . "\n"));
    }

    $details = $host;

    // Interface record(s)
    $i = 0;
    list($status, $introws, $interfaces) = db_get_records($onadb, 'interfaces', "host_id = {$host['id']}");

    
    // Now lets find interfaces we share with other hosts as subordinate
    list($status, $clustsubrows, $clustsub) = db_get_records($onadb, 'interface_clusters', "host_id = {$host['id']}");
    foreach($clustsub as $sub) {
        list($status, $clustintrows, $clustint) = ona_get_interface_record(array('id' => $sub['interface_id']));
        $interfaces[] = $clustint;
    }

    // Loop all our interfaces and gather information
    foreach($interfaces as $interface) {

        // Find out if the interface is a NAT interface and skip it
        list ($isnatstatus, $isnatrows, $isnat) = ona_get_interface_record(array('nat_interface_id' => $interface['id']));
        if ($isnatrows > 0) { continue; }
        $i++;

        // get subnet information
        list($status, $srows, $subnet) = ona_get_subnet_record(array('id' => $interface['subnet_id']));

        // check for shared interfaces
        list($status, $clust_rows, $clust) = db_get_records($onadb, 'interface_clusters', array('interface_id' => $interface['id']));
        if ($clust_rows) {
            list($status, $sharedhostrows, $sharedhost) = ona_get_host_record(array('id' => $clust[0]['host_id']));
            $interface['shared_host_secondary'] = $sharedhost['fqdn'];
            $interface['shared_host_primary'] = $host['fqdn'];
            list ($pristatus, $prirows, $priip) = ona_get_interface_record(array('host_id' => $host['id']));
            list ($secstatus, $secrows, $secip) = ona_get_interface_record(array('host_id' => $sharedhost['id']));
            $interface['shared_host_primary_ip_addr_text'] = $priip['ip_addr_text'];
            $interface['shared_host_secondary_ip_addr_text'] = $secip['ip_addr_text'];

            // if this is the secondary we need to figure out what the primary host is
            if ($sharedhost['fqdn'] == $host['fqdn']) {
                list($status, $hostprirows, $hostpri) = ona_get_host_record(array('id' => $interface['host_id']));
                $interface['shared_host_primary'] = $hostpri['fqdn'];

                list ($pristatus, $prirows, $priip) = ona_get_interface_record(array('host_id' => $interface['host_id']));
                list ($secstatus, $secrows, $secip) = ona_get_interface_record(array('host_id' => $sharedhost['id']));
                $interface['shared_host_primary_ip_addr_text'] = $priip['ip_addr_text'];
                $interface['shared_host_secondary_ip_addr_text'] = $secip['ip_addr_text'];
            }
        }

        // check for nat IPs
        if ($interface['nat_interface_id']) {
            list($status, $natrows, $natinterface) = ona_get_interface_record(array('id' => $interface['nat_interface_id']));
            $interface['nat_ip'] = $natinterface['ip_addr_text'];
        }

        // fixup some subnet data
        $subnet['ip_addr_text'] = ip_mangle($subnet['ip_addr'],'dotted');
        $subnet['ip_mask_text'] = ip_mangle($subnet['ip_mask'],'dotted');
        $subnet['ip_mask_cidr'] = ip_mangle($subnet['ip_mask'],'cidr');
        $interface['ip_addr_text'] = ip_mangle($interface['ip_addr'],'dotted');
        $subnetcalc = ipcalc_info($subnet['ip_addr'], $subnet['ip_mask']);
        $subnet['ip_broadcast_text'] = $subnetcalc['ip_last'];

        // Keep track of interface names
        $ona_ints .= "{$interface['name']},";

#        foreach ($interface as $key=>$val) if (strripos($key,'_id') !== false) unset($interface[$key]);
#        foreach ($subnet as $key=>$val) if (strripos($key,'_id') !== false) unset($subnet[$key]);

        // gather interface and subnets into an array for later
        $allints[$i] = $interface;
        $subnets['subnet_'.$subnet['id']] = $subnet;

    }

    // Add a list of interface names to the array
    $details['ona_int_names'] = implode(',',array_unique(explode(',',rtrim($ona_ints,','))));
    $details['ona_int_count'] = $i;

    // Append our interface info to the end
    foreach ($allints as $int) {
        $details['interface_id_'.$int['id']] = $int;
    }

    // Append our subnet info to the end
    foreach ($subnets as $net) {
        $details['subnet_id_'.$net['id']] = $net;
    }


    // Get rid of database id values
    // TODO: maybe make this an option to keep or remove values
# this breaks the subnet info due to _id being in it
#    foreach ($details as $key=>$val) if (strripos($key,'_id') !== false) unset($details[$key]);


    #print_r($details);

    if ($options['format'] == 'json') {
        $text = json_encode($details);
    } else {
        $text = yaml_emit($details);
    }

    // Return the success notice
    return(array(0, $text));
}

?>
