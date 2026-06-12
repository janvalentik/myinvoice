<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Http\Json;
use MyInvoice\Repository\DmsMessageRepository;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentTagRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Hlavní CRUD + metadata + koš + hromadné akce nad dokumenty. */
final class DocumentsAction
{
    use DocumentActionTrait;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentFolderRepository $folders,
        private readonly DocumentTagRepository $tags,
        private readonly DocumentLinkRepository $links,
        private readonly DmsMessageRepository $dms,
        private readonly DocumentStorage $storage,
        private readonly ActivityLogger $logger,
    ) {}

    /** GET /api/documents?folder_id=&doc_type= */
    public function list(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $q = $request->getQueryParams();
        $folderId = array_key_exists('folder_id', $q) ? $this->optInt($q['folder_id']) : null;
        $docType = isset($q['doc_type']) ? (string) $q['doc_type'] : null;
        $tag = isset($q['tag']) ? trim((string) $q['tag']) : '';

        // Filtr tagem je globální (napříč složkami) — vrátíme plochý seznam.
        if ($tag !== '') {
            return Json::ok($response, [
                'breadcrumb'     => [],
                'folders'        => [],
                'documents'      => $this->documents->listByTag($sid, $tag),
                'tag'            => $tag,
                'max_file_bytes' => $this->storage->maxFileBytes(),
            ]);
        }

        return Json::ok($response, [
            'breadcrumb'          => $this->breadcrumb($sid, $folderId),
            'folders'             => $this->folders->listChildren($sid, $folderId),
            'documents'           => $this->documents->listInFolder($sid, $folderId, $docType),
            'max_file_bytes'      => $this->storage->maxFileBytes(),
            'php_max_upload_bytes' => $this->phpUploadLimit(),
        ]);
    }

    /** Efektivní per-request limit PHP = min(post_max_size, upload_max_filesize); 0 = neomezeno. */
    private function phpUploadLimit(): int
    {
        $post = $this->iniBytes((string) ini_get('post_max_size'));
        $upload = $this->iniBytes((string) ini_get('upload_max_filesize'));
        $vals = array_filter([$post, $upload], static fn(int $v): bool => $v > 0);
        return $vals === [] ? 0 : min($vals);
    }

    private function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '') return 0;
        $unit = strtolower($v[strlen($v) - 1]);
        $num = (int) $v;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }

    /** GET /api/documents/{id} */
    public function get(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $doc = $this->documents->find($id, $sid, true);
        if ($doc === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $doc['tags']        = $this->tags->tagsForDocument($id);
        $doc['links']       = $this->links->linksForDocument($id, $sid);
        $doc['attachments'] = $this->documents->listChildren($id, $sid);
        $doc['breadcrumb']  = $this->breadcrumb($sid, $doc['folder_id']);
        if ($doc['doc_type'] === 'zfo') {
            $doc['dms_message'] = $this->dms->findByDocument($id);
        }
        return Json::ok($response, $doc);
    }

    /** PATCH /api/documents/{id} {title?, description?, tags?} */
    public function update(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $doc = $this->documents->find($id, $sid);
        if ($doc === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $body = (array) $request->getParsedBody();
        $title = array_key_exists('title', $body)
            ? mb_substr(trim((string) $body['title']), 0, 255)
            : (string) $doc['title'];
        if ($title === '') {
            return Json::error($response, 'title_required', 'Název je povinný.', 400);
        }
        $description = array_key_exists('description', $body)
            ? (trim((string) $body['description']) !== '' ? (string) $body['description'] : null)
            : $doc['description'];

        $this->documents->updateMeta($id, $sid, $title, $description);

        if (array_key_exists('tags', $body) && is_array($body['tags'])) {
            $this->tags->setTags($sid, $id, $body['tags']);
            $this->tags->purgeOrphans($sid);
        }
        $this->logger->log('document.updated', $this->userId($request), 'document', $id,
            ['title' => $title], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);

        return $this->get($request, $response, $args);
    }

    /** POST /api/documents/{id}/move {folder_id|null} */
    public function move(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $body = (array) $request->getParsedBody();
        $folderId = $this->optInt($body['folder_id'] ?? null);
        if ($folderId !== null && $this->folders->find($folderId, $sid) === null) {
            return Json::error($response, 'folder_not_found', 'Cílová složka nenalezena.', 404);
        }
        $this->documents->move($id, $sid, $folderId);
        return Json::ok($response, ['ok' => true]);
    }

    /** DELETE /api/documents/{id} — do koše. */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $this->documents->softDelete($id, $sid, $this->userId($request));
        $this->logger->log('document.trashed', $this->userId($request), 'document', $id,
            null, $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);
        return Json::ok($response, ['ok' => true]);
    }

    /** POST /api/documents/{id}/restore */
    public function restore(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid, true) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $this->documents->restore($id, $sid);
        return Json::ok($response, ['ok' => true]);
    }

    /** GET /api/documents/search?q= */
    public function search(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return Json::ok($response, ['documents' => [], 'query' => $q]);
        }
        return Json::ok($response, ['documents' => $this->documents->search($sid, $q), 'query' => $q]);
    }

    /** GET /api/documents/by-entity/{type}/{id} */
    public function byEntity(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $type = (string) ($args['type'] ?? '');
        $eid = (int) ($args['id'] ?? 0);
        if (!in_array($type, DocumentLinkRepository::ENTITY_TYPES, true)) {
            return Json::error($response, 'bad_entity', 'Neplatný typ entity.', 400);
        }
        return Json::ok($response, ['documents' => $this->documents->listByEntity($sid, $type, $eid)]);
    }

    /** GET /api/documents/tags */
    public function listTags(Request $request, Response $response): Response
    {
        return Json::ok($response, ['tags' => $this->tags->listForSupplier($this->supplierId($request))]);
    }

    /** POST /api/documents/{id}/links {entity_type, entity_id} */
    public function addLink(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $body = (array) $request->getParsedBody();
        $type = (string) ($body['entity_type'] ?? '');
        $eid = (int) ($body['entity_id'] ?? 0);
        if (!in_array($type, DocumentLinkRepository::ENTITY_TYPES, true) || $eid <= 0) {
            return Json::error($response, 'bad_entity', 'Neplatná vazba.', 400);
        }
        // Ověř, že cílová entita patří aktuálnímu dodavateli — jinak by vznikla
        // dangling/cizí vazba (read-back je sice scoped, ale nezakládáme smetí).
        if (!$this->links->entityBelongsToSupplier($type, $eid, $sid)) {
            return Json::error($response, 'not_found', 'Propojená entita nenalezena.', 404);
        }
        $this->links->attach($id, $type, $eid);
        return Json::ok($response, ['links' => $this->links->linksForDocument($id, $sid)]);
    }

    /** DELETE /api/documents/{id}/links {entity_type, entity_id} */
    public function removeLink(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->documents->find($id, $sid) === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $body = (array) $request->getParsedBody();
        $q = $request->getQueryParams();
        $type = (string) ($body['entity_type'] ?? $q['entity_type'] ?? '');
        $eid = (int) ($body['entity_id'] ?? $q['entity_id'] ?? 0);
        $this->links->detach($id, $type, $eid);
        return Json::ok($response, ['links' => $this->links->linksForDocument($id, $sid)]);
    }

    /** GET /api/documents/trash */
    public function trash(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        return Json::ok($response, [
            'documents' => $this->documents->listTrash($sid),
            'folders'   => $this->folders->listTrashed($sid),
        ]);
    }

    /** POST /api/documents/trash/empty — tvrdé smazání + dedup-aware mazání souborů. */
    public function emptyTrash(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $rows = $this->documents->listTrashedRaw($sid);
        $count = $this->documents->hardDeleteTrashed($sid);
        $this->folders->purgeTrashed($sid);
        $this->tags->purgeOrphans($sid); // osamocené tagy po skutečném smazání dokumentů

        // Po smazání DB řádků: fyzicky smaž soubory, na které už nikdo neukazuje.
        foreach ($rows as $r) {
            $this->storage->deleteIfOrphan(
                $sid,
                (string) $r['sha256'],
                (string) $r['filename'],
                isset($r['thumb_path']) ? (string) $r['thumb_path'] : null,
                $this->documents,
                [],
            );
        }
        $this->storage->pruneEmptyDirs($sid);
        $this->logger->log('document.trash_emptied', $this->userId($request), 'document', null,
            ['deleted' => $count], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);
        return Json::ok($response, ['ok' => true, 'deleted' => $count]);
    }

    /** POST /api/documents/bulk {action, ids[], folder_ids[]?, folder_id?, tags?} */
    public function bulk(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', (array) ($body['ids'] ?? []))));
        $folderIds = array_values(array_filter(array_map('intval', (array) ($body['folder_ids'] ?? []))));
        if ($ids === [] && $folderIds === []) {
            return Json::error($response, 'no_ids', 'Nebyly vybrány žádné položky.', 400);
        }

        $userId = $this->userId($request);
        $affected = 0;

        switch ($action) {
            case 'move':
                $folderId = $this->optInt($body['folder_id'] ?? null);
                if ($folderId !== null && $this->folders->find($folderId, $sid) === null) {
                    return Json::error($response, 'folder_not_found', 'Cílová složka nenalezena.', 404);
                }
                foreach ($ids as $id) {
                    if ($this->documents->move($id, $sid, $folderId)) $affected++;
                }
                // Složky: zákaz přesunu do sebe / vlastního potomka (cyklus).
                foreach ($folderIds as $fid) {
                    if ($folderId !== null && ($folderId === $fid
                        || in_array($folderId, $this->folders->descendantIds($fid, $sid), true))) {
                        continue;
                    }
                    if ($this->folders->move($fid, $sid, $folderId)) $affected++;
                }
                break;

            case 'delete':
                foreach ($ids as $id) {
                    if ($this->documents->softDelete($id, $sid, $userId)) $affected++;
                }
                foreach ($folderIds as $fid) {
                    if ($this->folders->find($fid, $sid) === null) continue;
                    $this->folders->softDeleteSubtree($fid, $sid, $userId);
                    $affected++;
                }
                break;

            case 'tag':
                $names = array_values(array_filter(array_map('strval', (array) ($body['tags'] ?? []))));
                foreach ($ids as $id) {
                    if ($this->documents->find($id, $sid) === null) continue;
                    foreach ($names as $name) {
                        $name = mb_substr(trim($name), 0, 64);
                        if ($name === '') continue;
                        $this->tags->attach($id, $this->tags->upsertTag($sid, $name));
                    }
                    $affected++;
                }
                break;

            default:
                return Json::error($response, 'bad_action', 'Neznámá hromadná akce.', 400);
        }

        $this->logger->log('document.bulk_' . $action, $userId, 'document', null,
            ['count' => $affected], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);
        return Json::ok($response, ['ok' => true, 'affected' => $affected]);
    }

    /** @return list<array{id:int,name:string}> Breadcrumb od rootu k aktuální složce. */
    private function breadcrumb(int $sid, ?int $folderId): array
    {
        $chain = [];
        $guard = 0;
        $cur = $folderId;
        while ($cur !== null && $guard++ < 64) {
            $f = $this->folders->find($cur, $sid, true);
            if ($f === null) break;
            array_unshift($chain, ['id' => (int) $f['id'], 'name' => (string) $f['name']]);
            $cur = $f['parent_id'] !== null ? (int) $f['parent_id'] : null;
        }
        return $chain;
    }
}
