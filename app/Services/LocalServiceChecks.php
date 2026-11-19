<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class LocalServiceChecks
{
    /** @return array<string, bool> */
    public function run(bool $probe=false): array
    {
        $default=config('database.default');
        $session=config('session.driver'); $cache=config('cache.default');
        $queue=config('queue.default'); $disk=config('filesystems.default');
        $checks=[
            'database_is_local_mysql'=>$this->localDatabase($default),
            'sessions_use_local_database'=>$session==='database' && $this->localDatabase(config('session.connection')?:$default),
            'cache_uses_local_database'=>config('cache.stores.'.$cache.'.driver')==='database'
                && $this->localDatabase(config('cache.stores.'.$cache.'.connection')?:$default)
                && $this->localDatabase(config('cache.stores.'.$cache.'.lock_connection')?:$default),
            'queue_uses_local_database'=>$this->localQueue($queue),
            'reminder_queue_uses_local_database'=>$this->localQueue('reminders') && config('queue.connections.reminders.after_commit')===true,
            'queue_history_uses_local_database'=>in_array(config('queue.failed.driver'),['database','database-uuids'],true)
                && $this->localDatabase(config('queue.failed.database')) && $this->localDatabase(config('queue.batching.database')),
            'default_files_use_local_disk'=>config('filesystems.disks.'.$disk.'.driver')==='local'
                && $this->localPath(config('filesystems.disks.'.$disk.'.root')),
            'protected_files_are_private_local'=>config('filesystems.disks.protected.driver')==='local'
                && config('filesystems.disks.protected.serve')===false && config('filesystems.disks.protected.visibility')==='private'
                && $this->privateRoot(config('filesystems.disks.protected.root')),
            'mail_has_no_network_transport'=>$this->localMail(),
            'logs_use_local_channels'=>$this->localLog(config('logging.default')),
            'maintenance_uses_local_file'=>config('app.maintenance.driver')==='file',
        ];
        if ($probe) {
            if (in_array(false,$checks,true)) { $checks['local_service_probe']=false; return $checks; }
            try {
                foreach (array_unique([$default,config('session.connection')?:$default,config('cache.stores.'.$cache.'.connection')?:$default,
                    config('cache.stores.'.$cache.'.lock_connection')?:$default,config('queue.connections.'.$queue.'.connection')?:$default,
                    config('queue.connections.reminders.connection')?:$default,config('queue.failed.database'),config('queue.batching.database')]) as $connection) {
                    DB::connection($connection)->select('SELECT 1');
                }
                $checks['local_service_probe']=true;
            } catch (Throwable) { $checks['local_service_probe']=false; }
            $root=config('filesystems.disks.protected.root');
            $checks['protected_root_available']=is_dir($root) && is_readable($root) && is_writable($root);
        }
        return $checks;
    }

    private function localDatabase(mixed $name): bool
    {
        if (!is_string($name) || !is_array($settings=config('database.connections.'.$name))) { return false; }
        if (($settings['driver']??null)!=='mysql' || !empty($settings['url'])) { return false; }
        foreach ([$settings,$settings['read']??[],$settings['write']??[]] as $endpoint) {
            if (!is_array($endpoint)) { return false; }
            foreach (array_keys($endpoint) as $key) { if (is_int($key)) { return false; } }
            foreach ((array)($endpoint['host']??$settings['host']??[]) as $host) {
                if (!in_array($host,['127.0.0.1','localhost','::1'],true)) { return false; }
            }
            if (!empty($endpoint['url'])) { return false; }
        }
        return !empty($settings['host']);
    }

    private function localQueue(mixed $name): bool
    {
        return is_string($name) && config('queue.connections.'.$name.'.driver')==='database'
            && $this->localDatabase(config('queue.connections.'.$name.'.connection')?:config('database.default'));
    }

    private function privateRoot(mixed $root): bool
    {
        if (!$this->localPath($root)) { return false; }
        $resolved=realpath($root); $public=realpath(public_path());
        if ($resolved===false || $public===false || !$this->localPath($resolved)) { return false; }
        $resolved=str_replace('\\','/',$resolved); $public=rtrim(str_replace('\\','/',$public),'/');
        if (PHP_OS_FAMILY==='Windows') { $resolved=strtolower($resolved); $public=strtolower($public); }
        return $resolved!==$public && !str_starts_with($resolved,$public.'/');
    }

    private function localPath(mixed $path): bool
    {
        return is_string($path) && $path!=='' && !str_contains($path,'://')
            && !str_starts_with(str_replace('\\','/',$path),'//');
    }

    private function localMail(): bool
    {
        $mailer=config('mail.mailers.'.config('mail.default'));
        if (!is_array($mailer)) { return false; }
        if (($mailer['transport']??null)==='array') { return true; }
        return ($mailer['transport']??null)==='log' && $this->localLog($mailer['channel']??config('logging.default'));
    }

    private function localLog(mixed $name,array $seen=[]): bool
    {
        if (!is_string($name) || in_array($name,$seen,true)) { return false; }
        $channel=config('logging.channels.'.$name);
        if (!is_array($channel)) { return false; }
        if (in_array($channel['driver']??null,['single','daily','monthly'],true)) {
            return $this->localPath($channel['path']??null);
        }
        if (($channel['driver']??null)==='monolog') { return ($channel['handler']??null)===\Monolog\Handler\NullHandler::class; }
        if (($channel['driver']??null)!=='stack' || empty($channel['channels'])) { return false; }
        foreach ($channel['channels'] as $child) { if (!$this->localLog($child,[...$seen,$name])) { return false; } }
        return true;
    }
}
