<?php
require __DIR__.'/bootstrap.php';
$limit=isset($argv[1])?max(1,min(100,(int)$argv[1])):20;$processed=0;
SOSSData::WithServiceNamespaces(eventsNamespaces(),function()use($limit,&$processed){
    $offset=0;do{$rows=\davvag_events\Store::rows('davvag_events_events',[],50,$offset);foreach($rows as $row){
        try{$e=\davvag_events\Store::load($row->id);$rev=$e['revision'];\davvag_events\Jobs::sweep($e);\davvag_events\Store::save($e,$rev,'worker','maintenance');
            while($processed<$limit&&($claim=\davvag_events\Jobs::claim($row->id))){\davvag_events\Jobs::execute($claim);$processed++;}
        }catch(\Throwable $error){fwrite(STDERR,"Event work deferred; inspect the host queue and server configuration.\n");}
        if($processed>=$limit)break 2;
    }$offset+=50;}while(count($rows)===50);
});echo "Processed $processed bounded jobs.\n";
