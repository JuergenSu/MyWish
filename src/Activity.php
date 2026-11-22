<?php
declare(strict_types=1);
namespace MyWish;

/** Bounded public activity metadata. Never records reservations, prices paid or logins. */
final class Activity
{
    public const LIMIT=500;
    public const TYPES=['userRegistered','listAdded','listUpdated','listRemoved','wishAdded','wishUpdated','wishRemoved'];

    public static function validate(mixed $log): void
    {
        if (!is_array($log) || count($log)!==2 || !isset($log['sequence'],$log['events']) || !is_int($log['sequence']) || $log['sequence']<0 || !is_array($log['events']) || !array_is_list($log['events']) || count($log['events'])>self::LIMIT) { throw new Problem(503,'Ungültige Änderungsübersicht.'); }
        $last=0;
        foreach ($log['events'] as $event) {
            if (!is_array($event) || count($event)!==4 || !is_int($event['sequence'] ?? null) || $event['sequence']<=$last || $event['sequence']>$log['sequence'] || !is_string($event['actorId'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$event['actorId']) || !in_array($event['type'] ?? '',self::TYPES,true) || !is_string($event['at'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D',$event['at'])) { throw new Problem(503,'Ungültiger Änderungseintrag.'); }
            $last=$event['sequence'];
        }
    }

    /** Called after image conversion, before the atomic manifest commit. */
    public static function record(array &$state,?array $before): void
    {
        // Migrating existing data is not an application event.
        if ($before===null || $state['settings']===null) { return; }
        $log=$before['settings']['activity'] ?? ['sequence'=>0,'events'=>[]];
        $users=array_column($state['users'],null,'id');$pending=[];
        $oldUsers=array_column($before['users'],null,'id');
        foreach ($users as $id=>$user) { if (!isset($oldUsers[$id])) { $pending[]=['userRegistered',$id]; } }
        $lists=array_column($state['lists'],null,'id')+array_column($before['lists'],null,'id');
        foreach (['lists'=>'list','wishes'=>'wish'] as $table=>$prefix) {
            $old=array_column($before[$table],null,'id');$current=array_column($state[$table],null,'id');
            foreach ($current as $id=>$row) {
                $actor=$table==='lists' ? $row['ownerUserId'] : $lists[$row['listId']]['ownerUserId'];
                if (!isset($old[$id])) { $pending[]=[$prefix.'Added',$actor]; }
                else {
                    $previous=$old[$id];$now=$row;
                    unset($previous['createdAt'],$previous['updatedAt'],$now['createdAt'],$now['updatedAt']);
                    ksort($previous);ksort($now);
                    if ($previous!==$now) { $pending[]=[$prefix.'Updated',$actor]; }
                }
            }
            foreach ($old as $id=>$row) {
                if (!isset($current[$id])) {
                    $actor=$table==='lists' ? $row['ownerUserId'] : $lists[$row['listId']]['ownerUserId'];
                    if (isset($users[$actor])) { $pending[]=[$prefix.'Removed',$actor]; }
                }
            }
        }
        // Account deletion removes its activity identifiers from the active dataset.
        $log['events']=array_values(array_filter($log['events'],fn($e)=>isset($users[$e['actorId']])));
        foreach ($pending as [$type,$actor]) {
            if ($log['sequence']===PHP_INT_MAX) { throw new Problem(503,'Der Änderungszähler ist ausgeschöpft.'); }
            $log['events'][]=['sequence'=>++$log['sequence'],'actorId'=>$actor,'type'=>$type,'at'=>gmdate('c')];
        }
        $log['events']=array_slice($log['events'],-self::LIMIT);
        if ($pending || isset($before['settings']['activity'])) { $state['settings']['activity']=$log; }
    }

    public static function since(array $state,string $userId,?int $cursor): array
    {
        $log=$state['settings']['activity'] ?? ['sequence'=>0,'events'=>[]];
        $first=$cursor===null || $cursor>$log['sequence'];$groups=[];
        $users=array_column($state['users'],null,'id');
        if (!$first) {
            foreach ($log['events'] as $event) {
                $actor=$event['actorId'];
                if ($event['sequence']<=$cursor || $actor===$userId || !isset($users[$actor])) { continue; }
                $groups[$actor] ??= ['actorId'=>$actor,'name'=>$users[$actor]['name'],'counts'=>[]];
                $type=$event['type'];$groups[$actor]['counts'][$type]=($groups[$actor]['counts'][$type] ?? 0)+1;
            }
        }
        $earliest=$log['events'][0]['sequence'] ?? $log['sequence']+1;
        return ['firstVisit'=>$first,'entries'=>array_values($groups),'truncated'=>!$first && $cursor<$earliest-1,'sequence'=>$log['sequence']];
    }
}
