<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Http\Resources\DocumentExportResource;
use App\Models\DocumentExport;
use App\Models\Permission;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class DocumentExports
{
    public function __construct(private WriteTransactions $transactions,private StockAuthority $authority,
        private ExportSources $sources,private ExportRendering $rendering,private AuditTrail $audit) {}

    public static function isCommand(?string $route): bool { return $route==='api.v1.exports.store'; }

    public function authorize(Request $request,User $actor): void
    {
        $this->authority->permission($actor,'exports.generate');
    }

    public function create(User $actor,array $input): JsonResponse
    {
        $actor=User::query()->lockForUpdate()->findOrFail($actor->id);
        $this->authority->permission($actor,'exports.generate');
        $source=$this->sources->snapshot($actor,$input); $now=now('UTC');
        $content=$this->rendering->render($source['data'],$input['format'],$now->toISOString());
        $export=new DocumentExport;
        $export->forceFill(['creator_id'=>$actor->id,'kind'=>$input['kind'],'format'=>$input['format'],
            'bundle'=>['input'=>$input,'source_hash'=>$source['fingerprint'],'authority_hash'=>$this->authorityHash($actor),'content'=>$content],
            'sha256'=>hash('sha256',$content),'byte_size'=>strlen($content),'created_at'=>$now,'expires_at'=>$now->copy()->addDay()])->save();
        $this->audit->record('export.generated',$actor->id,'document_export',$export->id);
        return (new DocumentExportResource($export))->response()->setStatusCode(201);
    }

    public function show(User $actor,int $id): JsonResponse
    {
        return $this->fenced($actor,function (User $viewer) use ($id): JsonResponse {
            [$export]=$this->current($viewer,$id);
            $this->audit->record('export.viewed',$viewer->id,'document_export',$export->id);
            return (new DocumentExportResource($export))->response();
        });
    }

    public function replay(Request $request,User $actor,stdClass $command): JsonResponse
    {
        [$export]=$this->current($actor,(int)$command->resource_id,generate:true);
        return (new DocumentExportResource($export))->response();
    }

    public function download(User $actor,int $id,bool $print): Response
    {
        return $this->fenced($actor,function (User $viewer) use ($id,$print): Response {
            [$export,$bundle]=$this->current($viewer,$id);
            abort_if($print && $export->format!=='html',422);
            $this->audit->record($print?'export.printed':'export.downloaded',$viewer->id,'document_export',$export->id);
            return response($bundle['content'],200,[
                'Content-Type'=>$export->format==='html'?'text/html; charset=UTF-8':'text/csv; charset=UTF-8',
                'Content-Disposition'=>($print?'inline':'attachment').'; filename="document-'.$export->id.'.'.$export->format.'"',
                'Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff',
                'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; sandbox allow-modals",
                'Referrer-Policy'=>'no-referrer','X-Frame-Options'=>'DENY']);
        });
    }

    private function current(User $actor,int $id,bool $generate=false): array
    {
        $this->authority->permission($actor,$generate?'exports.generate':'exports.download');
        $export=DocumentExport::query()->where('creator_id',$actor->id)->lockForUpdate()->findOrFail($id);
        if (now('UTC')->greaterThanOrEqualTo($export->expires_at)) { throw new WriteConflict('export_expired','This document expired. Generate a fresh copy.'); }
        $bundle=$export->bundle;
        if ($export->kind!==$bundle['input']['kind'] || $export->format!==$bundle['input']['format']) {
            throw new WriteConflict('export_integrity_failed','This document could not be verified. Generate a fresh copy.');
        }
        if (!hash_equals($this->authorityHash($actor),$bundle['authority_hash'])) {
            throw new WriteConflict('export_outdated','Your access changed. Generate a fresh copy.');
        }
        $source=$this->sources->snapshot($actor,$bundle['input']);
        if (!hash_equals($source['fingerprint'],$bundle['source_hash'])) {
            throw new WriteConflict('export_outdated','The saved source changed. Generate a fresh copy.');
        }
        if (strlen($bundle['content'])!==$export->byte_size || !hash_equals(hash('sha256',$bundle['content']),$export->sha256)) {
            throw new WriteConflict('export_integrity_failed','This document could not be verified. Generate a fresh copy.');
        }
        return [$export,$bundle];
    }

    private function authorityHash(User $actor): string
    {
        return hash('sha256',json_encode([$actor->id,$actor->version,$actor->department_id,
            $actor->permissionCodes()->all(),$actor->roles()->orderBy('code')->pluck('code')->all(),
            \App\Models\StockStoreAccess::query()->where('user_id',$actor->id)->where('is_active',true)->orderBy('store_id')->pluck('store_id')->all()],JSON_THROW_ON_ERROR));
    }

    private function fenced(User $actor,Closure $operation): mixed
    {
        return $this->transactions->run(function () use ($actor,$operation): mixed {
            foreach (['staff.manage','patients.create'] as $code) { Permission::query()->where('code',$code)->lockForUpdate()->firstOrFail(); }
            $viewer=User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($viewer->is_active,403);
            return $operation($viewer);
        });
    }
}
