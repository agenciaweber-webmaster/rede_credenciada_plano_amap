<?php

namespace BuscaCep\Controllers;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use BuscaCep\Helpers\Helper;
use BuscaCep\Models\Storage;

/**
 * Gerencia operações CRUD de revendas, importação/exportação e geolocalização.
 */
class Resale
{
    private const IMPORT_BATCH_SIZE = 100;

    private $helper;
    private $storage;

    public function __construct()
    {
        $this->helper = new Helper();
        $this->storage = new Storage();
    }

    public function create(WP_REST_Request $request)
    {
        $param = $request->get_params();
        $cep = preg_replace('/[^\d\-]/', '', $param['cep'] ?? '');

        if (strlen($cep) < 8 || strlen($cep) > 9 || !isset($param['numero'])) {
            return new WP_Error('Bad Request', __('CEP incorreto ou não informado', 'busca-cep'), ['status' => 400]);
        }

        $result = $this->getGeo($cep, $param['numero']);

        if (!empty($result->error)) {
            return new WP_Error(
                'Bad Request',
                __('Ocorreu um erro ao processar sua requisição, localidade não encontrada.', 'busca-cep'),
                ['status' => 400]
            );
        }

        $data = $this->buildResaleData($param, $result, $cep);
        $validate = $this->storage->validate($data);

        if (isset($validate->quantity)) {
            $data['id'] = $validate->id;
            $response = $this->storage->update($data);
        } else {
            $response = $this->storage->insert($data);
        }

        if (!empty($response['status'])) {
            return new WP_REST_Response([
                'status'        => 'ok',
                'response'      => 'application/json',
                'body_response' => $response['message'],
            ]);
        }

        return new WP_Error(
            'Bad Request',
            __('Ocorreu um erro ao armazenar os dados, tente novamente.', 'busca-cep'),
            ['status' => 400]
        );
    }

    /**
     * Atualiza uma revenda existente.
     */
    public function update(WP_REST_Request $request)
    {
        $param = $request->get_params();
        $cep = preg_replace('/[^\d\-]/', '', $param['cep'] ?? '');

        if (strlen($cep) < 8 || strlen($cep) > 9 || !isset($param['numero'])) {
            return new WP_Error(
                'Bad Request',
                __('CEP incorreto, não informado ou fora da base de dados', 'busca-cep'),
                ['status' => 400]
            );
        }

        $result = $this->getGeo($cep, $param['numero']);

        $data = $this->buildResaleData($param, $result, $cep);
        $data['id'] = $param['id'];

        $response = $this->storage->update($data);

        if (!empty($response['status'])) {
            return new WP_REST_Response([
                'status'        => 'ok',
                'response'      => 'application/json',
                'body_response' => $response['message'],
            ]);
        }

        return new WP_Error(
            'Bad Request',
            __('Ocorreu um erro ao processar, entre em contato com os desenvolvedores!', 'busca-cep'),
            ['status' => 400]
        );
    }

    /**
     * Exclui uma revenda.
     */
    public function delete(WP_REST_Request $request)
    {
        $param = $request->get_params();
        $response = $this->storage->delete($param['id']);

        if (!empty($response['status'])) {
            return new WP_REST_Response([
                'status'        => 'ok',
                'response'      => 'application/json',
                'body_response' => $response['message'],
            ]);
        }

        return new WP_Error(
            'Bad Request',
            __('Ocorreu um erro ao processar, entre em contato com os desenvolvedores!', 'busca-cep'),
            ['status' => 400]
        );
    }

    /**
     * Salva configurações do plugin (token da API de geocoding).
     */
    public function config(WP_REST_Request $request)
    {
        $params = $request->get_params();
        $response = $this->storage->config($params['geo_token'] ?? '');

        if (!empty($response['status'])) {
            return new WP_REST_Response([
                'status'        => 'ok',
                'response'      => 'application/json',
                'body_response' => $response['message'],
            ]);
        }

        return new WP_Error(
            'Bad Request',
            __('Não foi possível armazenar os dados, tente novamente.', 'busca-cep'),
            ['status' => 400, 'response' => 'application/json', 'body_response' => $response['message']]
        );
    }

    /**
     * Etapa 1: recebe o CSV, faz parse e prepara a importação em lotes.
     */
    public function uploadFileInit(WP_REST_Request $request)
    {
        try {
            $this->extendImportTimeLimit();
            $this->cleanupStaleImports();

            $files = $this->resolveUploadFiles($request);
            $validation = $this->validateImportUpload($files);
            if ($validation instanceof WP_REST_Response) {
                return $validation;
            }

            $importId = 'imp_' . preg_replace('/[^a-z0-9]/i', '', wp_generate_password(12, false, false));
            $prepared = $this->prepareImportRowsFile($files['import']['tmp_name'], $importId);
            if ($prepared instanceof WP_REST_Response) {
                return $prepared;
            }

            $params = $request->get_params();
            $syncMode = $this->isImportSyncModeEnabled($params);

            $meta = [
                'created_at'     => time(),
                'sync_mode'      => $syncMode,
                'delimiter'      => $prepared['delimiter'],
                'header_values'  => $prepared['header_values'],
                'next_index'     => 0,
                'total'          => $prepared['total'],
                'total_saved'    => 0,
                'geo_api_calls'  => 0,
                'geo_reused'     => 0,
                'unchanged'      => 0,
                'erros'          => 0,
                'ignorados'      => 0,
                'erro_geocoding' => null,
            ];

            if (!$this->saveImportMeta($importId, $meta)) {
                $this->deleteImportArtifacts($importId);

                return new WP_REST_Response([
                    'success' => false,
                    'error'   => __('Não foi possível preparar a importação. Verifique permissões da pasta de uploads.', 'busca-cep'),
                ], 500);
            }

            $lookup = $this->buildImportLookupIndexes($this->safeGetResales());
            $this->saveImportDupIndex($importId, $lookup['dup']);
            $this->saveImportBusinessIndex($importId, $lookup['business']);
            $this->saveImportAddressGeoIndex($importId, $lookup['address']);

            if ($syncMode) {
                $this->saveImportExistingIds($importId, $this->collectResaleIds($this->safeGetResales()));
            }

            return new WP_REST_Response([
                'success'    => true,
                'import_id'  => $importId,
                'total'      => $prepared['total'],
                'batch_size' => self::IMPORT_BATCH_SIZE,
                'delimiter'  => $prepared['delimiter'],
                'sync_mode'  => $syncMode,
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => $this->importExceptionMessage($e, __('Erro interno ao preparar a importação.', 'busca-cep')),
            ], 500);
        }
    }

    /**
     * Etapa 2: geocodifica e persiste um lote de linhas (evita timeout do servidor).
     */
    public function uploadFileProcess(WP_REST_Request $request)
    {
        try {
            $this->extendImportTimeLimit();

            $params = $request->get_json_params();
            if (!is_array($params)) {
                $params = $request->get_params();
            }

            $importId = trim((string) ($params['import_id'] ?? ''));
            if ($importId === '') {
                return new WP_REST_Response(['success' => false, 'error' => __('Importação não informada.', 'busca-cep')], 400);
            }

            $meta = $this->loadImportMeta($importId);
            if ($meta === null) {
                return new WP_REST_Response([
                    'success' => false,
                    'error'   => __('Sessão de importação expirada ou inválida. Envie o arquivo novamente.', 'busca-cep'),
                ], 404);
            }

            $totalRows = (int) ($meta['total'] ?? 0);
            if ($totalRows === 0) {
                $this->deleteImportArtifacts($importId);

                return new WP_REST_Response([
                    'success'        => true,
                    'finished'       => true,
                    'import_success' => false,
                    'error'          => __('Arquivo sem linhas de dados para importar.', 'busca-cep'),
                    'processed'      => 0,
                    'total'          => 0,
                ], 200);
            }

            $businessIndex = $this->loadImportBusinessIndex($importId) ?? [];
            $addressGeoIndex = array_merge(
                $this->loadImportAddressGeoIndex($importId) ?? [],
                $this->loadRuntimeGeoCache($importId)
            );
            $batchKeys = $this->loadImportBatchKeys($importId);
            $geoCache = [];
            $pendingRows = $this->processImportBatchFromFile(
                $importId,
                $meta,
                self::IMPORT_BATCH_SIZE,
                $businessIndex,
                $addressGeoIndex,
                $batchKeys,
                $geoCache
            );

            if (!empty($pendingRows)) {
                $this->extendImportTimeLimit(300);
                $chunk = $this->persistImportResultsChunk($pendingRows);
                if (!empty($chunk['error'])) {
                    $this->saveImportMeta($importId, $meta);

                    return new WP_REST_Response([
                        'success'        => true,
                        'finished'       => true,
                        'import_success' => false,
                        'error'          => $chunk['error'],
                        'processed'      => (int) $meta['next_index'],
                        'total'          => (int) $meta['total'],
                        'total_saved'    => (int) ($meta['total_saved'] ?? 0),
                        'erros'          => (int) $meta['erros'],
                    ], 200);
                }
                $meta['total_saved'] = (int) ($meta['total_saved'] ?? 0) + (int) $chunk['total'];
            }

            $this->saveImportMeta($importId, $meta);

            $processed = (int) $meta['next_index'];
            $finished = $processed >= $totalRows;

            if (!$finished) {
                return new WP_REST_Response([
                    'success'     => true,
                    'finished'    => false,
                    'processed'   => $processed,
                    'total'       => $totalRows,
                    'total_saved'    => (int) ($meta['total_saved'] ?? 0),
                    'geo_reused'     => (int) ($meta['geo_reused'] ?? 0),
                    'geo_api_calls'  => (int) ($meta['geo_api_calls'] ?? 0),
                    'unchanged'      => (int) ($meta['unchanged'] ?? 0),
                    'next_offset' => $processed,
                    'erros'       => (int) $meta['erros'],
                ], 200);
            }

            $totalSaved = (int) ($meta['total_saved'] ?? 0);
            $unchanged = (int) ($meta['unchanged'] ?? 0);
            $removed = 0;

            if (!empty($meta['sync_mode']) && ($totalSaved > 0 || $unchanged > 0)) {
                $removed = $this->syncDeleteUnseenRecords($importId);
                $meta['removed'] = $removed;
            }

            $this->deleteImportArtifacts($importId);

            if ($totalSaved === 0 && $unchanged === 0) {
                $msg = 'Nenhum registro foi importado.';
                if ($meta['erros'] > 0 && !empty($meta['erro_geocoding'])) {
                    $msg .= ' ' . $meta['erro_geocoding'];
                } elseif ($meta['erros'] > 0) {
                    $msg .= ' Verifique se as colunas nome, cep e numero estão preenchidas e se o token da API Google está configurado corretamente.';
                }

                return new WP_REST_Response([
                    'success'        => true,
                    'finished'       => true,
                    'import_success' => false,
                    'error'          => $msg,
                    'processed'      => $processed,
                    'total'          => $totalRows,
                    'erros'          => (int) $meta['erros'],
                ], 200);
            }

            $msg = 'Importação concluída.';
            if ($meta['ignorados'] > 0) {
                $msg .= " {$meta['ignorados']} linha(s) duplicada(s) ou ignorada(s).";
            }
            if ($unchanged > 0) {
                $msg .= " {$unchanged} já existente(s) sem alteração (sem custo de API).";
            }
            if (!empty($meta['geo_reused'])) {
                $msg .= " {$meta['geo_reused']} coordenada(s) reutilizada(s) da base.";
            }
            if (!empty($meta['geo_api_calls'])) {
                $msg .= " {$meta['geo_api_calls']} consulta(s) à API Google.";
            }
            if ($removed > 0) {
                $msg .= " {$removed} registro(s) ausente(s) na planilha foram excluído(s).";
            }
            $recordCount = count($this->safeGetResales());
            $msg .= " Total na base: " . number_format($recordCount, 0, '', '.') . ' cadastro(s).';
            if ((int) $meta['erros'] > 0) {
                $msg .= ' ' . (int) $meta['erros'] . ' linha(s) com erro.';
            }

            return new WP_REST_Response([
                'success'        => true,
                'finished'       => true,
                'import_success' => true,
                'msg'            => $msg,
                'processed'      => $processed,
                'total'          => $totalRows,
                'total_saved'    => $totalSaved,
                'record_count'   => $recordCount,
                'removed'        => $removed,
                'sync_mode'      => !empty($meta['sync_mode']),
                'erros'          => (int) $meta['erros'],
                'ignorados'      => (int) $meta['ignorados'],
                'unchanged'      => $unchanged,
                'geo_reused'     => (int) ($meta['geo_reused'] ?? 0),
                'geo_api_calls'  => (int) ($meta['geo_api_calls'] ?? 0),
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => $this->importExceptionMessage($e, __('Erro interno durante a importação.', 'busca-cep')),
            ], 500);
        }
    }

    /**
     * @deprecated Use uploadFileInit + uploadFileProcess. Mantido por compatibilidade.
     */
    public function uploadFile(WP_REST_Request $request)
    {
        $init = $this->uploadFileInit($request);
        $data = $init->get_data();
        if (empty($data['success'])) {
            return $init;
        }

        $importId = $data['import_id'];
        $process = null;
        do {
            $batchRequest = new WP_REST_Request('POST');
            $batchRequest->set_param('import_id', $importId);
            $process = $this->uploadFileProcess($batchRequest);
            $batch = $process->get_data();
        } while (!empty($batch['success']) && empty($batch['finished']));

        return $process;
    }

    public function export()
    {
        $header = ['nome', 'plano', 'especialidade', 'cnpj/crm', 'whatsapp', 'telefone', 'horario', 'cep', 'rua', 'numero', 'bairro', 'municipio', 'estado', 'pais', 'status'];
        $results = $this->storage->getResales('*');
        $rows = [$header];

        foreach ($results as $r) {
            $r = (array) $r;
            $rows[] = [
                $this->getNome($r),
                $r['plano'] ?? '',
                $r['especialidade'] ?? '',
                $r['cnpj'] ?? '',
                $r['whatsapp'] ?? '',
                $r['telefone'] ?? '',
                $r['horario'] ?? '',
                $r['cep'] ?? '',
                $r['rua'] ?? '',
                $r['numero'] ?? '',
                $r['bairro'] ?? '',
                $r['municipio'] ?? '',
                $r['estado'] ?? '',
                $r['pais'] ?? '',
                $r['status'] ?? 'ativo',
            ];
        }

        $date = date('d-m-Y');
        $filename = "Rede-Credenciada-{$date}.csv";
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"');
        }
        fclose($out);
        exit;
    }

    private function extendImportTimeLimit(int $seconds = 120): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
    }

    private function resolveUploadFiles(WP_REST_Request $request): array
    {
        $files = $request->get_file_params();
        if (empty($files)) {
            $files = isset($_FILES) ? $_FILES : [];
        }

        return $files;
    }

    private function importExceptionMessage(\Throwable $e, string $fallback): string
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return $fallback . ' (' . $e->getMessage() . ')';
        }

        return $fallback;
    }

    private function validateImportUpload(array $files)
    {
        if (!isset($files['import']) || empty($files['import']['name'])) {
            return new WP_REST_Response(['success' => false, 'error' => __('Por favor selecione um arquivo', 'busca-cep')], 400);
        }

        if (strtolower(pathinfo($files['import']['name'], PATHINFO_EXTENSION)) !== 'csv') {
            return new WP_REST_Response(['success' => false, 'error' => __('Formato incompatível. Use CSV.', 'busca-cep')], 400);
        }

        if (empty($files['import']['tmp_name']) || !is_readable($files['import']['tmp_name'])) {
            return new WP_REST_Response(['success' => false, 'error' => __('Não foi possível ler o arquivo.', 'busca-cep')], 400);
        }

        return true;
    }

    /**
     * Converte o CSV enviado em arquivo JSONL (uma linha por registro) para leitura em lotes.
     *
     * @return array{total: int, delimiter: string, header_values: array<int, string>}|WP_REST_Response
     */
    private function prepareImportRowsFile(string $tmpPath, string $importId)
    {
        $handle = @fopen($tmpPath, 'rb');
        if ($handle === false) {
            return new WP_REST_Response(['success' => false, 'error' => __('Não foi possível ler o arquivo.', 'busca-cep')], 400);
        }

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $delimiter = $this->detectCsvDelimiter($handle);
        $headerLine = fgetcsv($handle, 0, $delimiter, '"');
        if ($headerLine === false || $headerLine === [null] || empty(array_filter($headerLine, function ($v) {
            return $v !== null && trim((string) $v) !== '';
        }))) {
            fclose($handle);

            return new WP_REST_Response(['success' => false, 'error' => 'Arquivo vazio ou sem cabeçalho.'], 400);
        }

        $headerValues = $this->normalizeImportHeaders($headerLine);
        $rowsPath = $this->importRowsPath($importId);
        $rowsHandle = @fopen($rowsPath, 'wb');
        if ($rowsHandle === false) {
            fclose($handle);

            return new WP_REST_Response([
                'success' => false,
                'error'   => __('Não foi possível gravar arquivo temporário da importação.', 'busca-cep'),
            ], 500);
        }

        $total = 0;
        while (($cells = fgetcsv($handle, 0, $delimiter, '"')) !== false) {
            if (!$this->csvRowHasContent($cells)) {
                continue;
            }

            $row = @array_combine(
                $headerValues,
                array_pad(array_slice($cells, 0, count($headerValues)), count($headerValues), '')
            );
            if ($row === false) {
                continue;
            }

            $normalized = array_map(static function ($v) {
                return is_string($v) ? $v : (string) ($v ?? '');
            }, $row);

            $line = wp_json_encode($normalized, JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                continue;
            }

            fwrite($rowsHandle, $line . "\n");
            $total++;
        }

        fclose($handle);
        fclose($rowsHandle);

        if ($total === 0) {
            @unlink($rowsPath);

            return new WP_REST_Response(['success' => false, 'error' => 'Arquivo sem linhas de dados para importar.'], 400);
        }

        return [
            'total'         => $total,
            'delimiter'     => $delimiter,
            'header_values' => $headerValues,
        ];
    }

    private function detectCsvDelimiter($handle): string
    {
        $pos = ftell($handle);
        $line = fgets($handle);
        if ($line === false) {
            fseek($handle, $pos);

            return ',';
        }
        fseek($handle, $pos);

        $semi = substr_count($line, ';');
        $comma = substr_count($line, ',');

        return $semi > $comma ? ';' : ',';
    }

    /**
     * @param array<int, string|null> $headerLine
     * @return array<int, string>
     */
    private function normalizeImportHeaders(array $headerLine): array
    {
        $headerValues = [];
        foreach ($headerLine as $hv) {
            $headerValues[] = strtolower(trim(preg_replace('/\s+/', '_', $this->helper->formatString($hv ?? ''))));
        }

        $headerAliases = [
            'razao_social'  => 'razao',
            'nome_fantasia' => 'fantasia',
            'localidade'    => 'municipio',
            'cidade'        => 'municipio',
            'cnpj/crm'      => 'cnpj',
            'cnpj_crm'      => 'cnpj',
        ];

        return array_map(static function ($h) use ($headerAliases) {
            return $headerAliases[$h] ?? $h;
        }, $headerValues);
    }

    /**
     * @param array<int, string|null> $cells
     */
    private function csvRowHasContent(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== null && trim((string) $c) !== '') {
                return true;
            }
        }

        return false;
    }

    private function safeGetResales(): array
    {
        try {
            return $this->storage->getResales('*');
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getImportTempDir(): string
    {
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            throw new \RuntimeException($upload['error']);
        }

        $dir = $upload['basedir'] . '/busca-cep-imports';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new \RuntimeException('Não foi possível criar pasta temporária de importação.');
        }

        return $dir;
    }

    private function importSafeId(string $importId): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $importId);
    }

    private function importMetaPath(string $importId): string
    {
        return $this->getImportTempDir() . '/' . $this->importSafeId($importId) . '.meta.json';
    }

    private function importRowsPath(string $importId): string
    {
        return $this->getImportTempDir() . '/' . $this->importSafeId($importId) . '.rows.jsonl';
    }

    private function importPendingPath(string $importId): string
    {
        return $this->getImportTempDir() . '/' . $this->importSafeId($importId) . '.pending.jsonl';
    }

    private function importDupIndexPath(string $importId): string
    {
        return $this->getImportTempDir() . '/' . $this->importSafeId($importId) . '.dup-index.json';
    }

    private function importBatchKeysPath(string $importId): string
    {
        return $this->getImportTempDir() . '/' . $this->importSafeId($importId) . '.batch-keys.txt';
    }

    private function importBusinessIndexPath(string $importId): string
    {
        return $this->getImportTempDir() . '/' . $this->importSafeId($importId) . '.business-index.json';
    }

    private function importAddressGeoIndexPath(string $importId): string
    {
        return $this->getImportTempDir() . '/' . $this->importSafeId($importId) . '.address-geo-index.json';
    }

    private function importRuntimeGeoCachePath(string $importId): string
    {
        return $this->getImportTempDir() . '/' . $this->importSafeId($importId) . '.runtime-geo.jsonl';
    }

    private function importExistingIdsPath(string $importId): string
    {
        return $this->getImportTempDir() . '/' . $this->importSafeId($importId) . '.existing-ids.json';
    }

    private function importSeenIdsPath(string $importId): string
    {
        return $this->getImportTempDir() . '/' . $this->importSafeId($importId) . '.seen-ids.txt';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function isImportSyncModeEnabled(array $params): bool
    {
        if (!isset($params['sync_mode'])) {
            return false;
        }

        $val = $params['sync_mode'];
        if (is_bool($val)) {
            return $val;
        }

        $str = strtolower(trim((string) $val));

        return in_array($str, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param array<int, array<string, mixed>> $resales
     * @return array<int, int>
     */
    private function collectResaleIds(array $resales): array
    {
        $ids = [];
        foreach ($resales as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<int, int> $ids
     */
    private function saveImportExistingIds(string $importId, array $ids): void
    {
        $json = wp_json_encode(array_values($ids), JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            file_put_contents($this->importExistingIdsPath($importId), $json, LOCK_EX);
        }
    }

    /**
     * @return array<int, int>
     */
    private function loadImportExistingIds(string $importId): array
    {
        $path = $this->importExistingIdsPath($importId);
        if (!is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<int, true>
     */
    private function loadImportSeenIds(string $importId): array
    {
        $path = $this->importSeenIdsPath($importId);
        if (!is_readable($path)) {
            return [];
        }

        $seen = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $id = (int) trim($line);
            if ($id > 0) {
                $seen[$id] = true;
            }
        }

        fclose($handle);

        return $seen;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function markImportSeenId(string $importId, int $id, array $meta): void
    {
        if ($id <= 0 || empty($meta['sync_mode'])) {
            return;
        }

        file_put_contents($this->importSeenIdsPath($importId), $id . "\n", FILE_APPEND | LOCK_EX);
    }

    private function syncDeleteUnseenRecords(string $importId): int
    {
        $existing = $this->loadImportExistingIds($importId);
        if (empty($existing)) {
            return 0;
        }

        $seen = $this->loadImportSeenIds($importId);
        if (empty($seen)) {
            return 0;
        }

        $toDelete = [];
        foreach ($existing as $id) {
            if (!isset($seen[$id])) {
                $toDelete[] = $id;
            }
        }

        if (empty($toDelete)) {
            return 0;
        }

        return $this->storage->bulkDelete($toDelete);
    }

    /**
     * @param array<string, int> $index
     */
    private function saveImportDupIndex(string $importId, array $index): void
    {
        $json = wp_json_encode($index, JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            file_put_contents($this->importDupIndexPath($importId), $json, LOCK_EX);
        }
    }

    /**
     * @return array<string, int>|null
     */
    private function loadImportDupIndex(string $importId): ?array
    {
        $path = $this->importDupIndexPath($importId);
        if (!is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, true>
     */
    private function loadImportBatchKeys(string $importId): array
    {
        $path = $this->importBatchKeysPath($importId);
        if (!is_readable($path)) {
            return [];
        }

        $keys = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $key = trim($line);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        fclose($handle);

        return $keys;
    }

    private function appendImportBatchKey(string $importId, string $key): void
    {
        file_put_contents($this->importBatchKeysPath($importId), $key . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<string, array<string, mixed>> $index
     */
    private function saveImportBusinessIndex(string $importId, array $index): void
    {
        $json = wp_json_encode($index, JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            file_put_contents($this->importBusinessIndexPath($importId), $json, LOCK_EX);
        }
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function loadImportBusinessIndex(string $importId): ?array
    {
        $path = $this->importBusinessIndexPath($importId);
        if (!is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, array{lat: float, lng: float}> $index
     */
    private function saveImportAddressGeoIndex(string $importId, array $index): void
    {
        $json = wp_json_encode($index, JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            file_put_contents($this->importAddressGeoIndexPath($importId), $json, LOCK_EX);
        }
    }

    /**
     * @return array<string, array{lat: float, lng: float}>|null
     */
    private function loadImportAddressGeoIndex(string $importId): ?array
    {
        $path = $this->importAddressGeoIndexPath($importId);
        if (!is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Coordenadas obtidas via Google nesta importação (reutilizadas entre lotes).
     *
     * @return array<string, array{lat: float, lng: float}>
     */
    private function loadRuntimeGeoCache(string $importId): array
    {
        $path = $this->importRuntimeGeoCachePath($importId);
        if (!is_readable($path)) {
            return [];
        }

        $cache = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $entry = json_decode(trim($line), true);
            if (!is_array($entry) || empty($entry['k'])) {
                continue;
            }
            $cache[(string) $entry['k']] = [
                'lat' => (float) ($entry['lat'] ?? 0),
                'lng' => (float) ($entry['lng'] ?? 0),
            ];
        }

        fclose($handle);

        return $cache;
    }

    private function appendRuntimeGeoCache(string $importId, string $addressKey, float $lat, float $lng): void
    {
        $line = wp_json_encode(['k' => $addressKey, 'lat' => $lat, 'lng' => $lng], JSON_UNESCAPED_UNICODE);
        if ($line !== false) {
            file_put_contents($this->importRuntimeGeoCachePath($importId), $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadImportMeta(string $importId): ?array
    {
        $path = $this->importMetaPath($importId);
        if (!is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $meta = json_decode($raw, true);

        return is_array($meta) ? $meta : null;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function saveImportMeta(string $importId, array $meta): bool
    {
        $json = wp_json_encode($meta, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->importMetaPath($importId), $json, LOCK_EX) !== false;
    }

    private function deleteImportArtifacts(string $importId): void
    {
        foreach ([
            $this->importMetaPath($importId),
            $this->importRowsPath($importId),
            $this->importPendingPath($importId),
            $this->importDupIndexPath($importId),
            $this->importBusinessIndexPath($importId),
            $this->importAddressGeoIndexPath($importId),
            $this->importRuntimeGeoCachePath($importId),
            $this->importBatchKeysPath($importId),
            $this->importExistingIdsPath($importId),
            $this->importSeenIdsPath($importId),
            $this->getImportTempDir() . '/' . $this->importSafeId($importId) . '.json',
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function cleanupStaleImports(): void
    {
        $dir = $this->getImportTempDir();
        $patterns = ['*.meta.json', '*.rows.jsonl', '*.pending.jsonl', '*.dup-index.json', '*.business-index.json', '*.address-geo-index.json', '*.runtime-geo.jsonl', '*.batch-keys.txt', '*.existing-ids.json', '*.seen-ids.txt', '*.json'];
        $limit = time() - (6 * (defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600));

        foreach ($patterns as $pattern) {
            $files = glob($dir . '/' . $pattern);
            if ($files === false) {
                continue;
            }
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $limit) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, array<string, mixed>> $businessIndex
     * @param array<string, array{lat: float, lng: float}> $addressGeoIndex
     * @param array<string, true> $batchKeys
     * @param array<string, array<string, mixed>> $geoCache
     * @return array<int, array<string, mixed>>
     */
    private function processImportBatchFromFile(
        string $importId,
        array &$meta,
        int $batchSize,
        array $businessIndex,
        array $addressGeoIndex,
        array &$batchKeys,
        array &$geoCache
    ): array {
        $path = $this->importRowsPath($importId);
        if (!is_readable($path)) {
            throw new \RuntimeException('Arquivo temporário da importação não encontrado.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível abrir arquivo temporário da importação.');
        }

        $start = (int) ($meta['next_index'] ?? 0);
        $processedInBatch = 0;
        $pendingRows = [];
        $currentLine = 0;

        while (($line = fgets($handle)) !== false) {
            if ($currentLine < $start) {
                $currentLine++;
                continue;
            }
            if ($processedInBatch >= $batchSize) {
                break;
            }

            $row = json_decode(trim($line), true);
            if (!is_array($row)) {
                $meta['erros'] = (int) ($meta['erros'] ?? 0) + 1;
                $currentLine++;
                $processedInBatch++;
                continue;
            }

            $pending = $this->processImportRow(
                $importId,
                $row,
                $meta,
                $businessIndex,
                $addressGeoIndex,
                $batchKeys,
                $geoCache
            );
            if ($pending !== null) {
                $pendingRows[] = $pending;
            }

            $currentLine++;
            $processedInBatch++;
        }

        fclose($handle);
        $meta['next_index'] = $start + $processedInBatch;

        return $pendingRows;
    }

    /**
     * @param array<string, string> $row
     * @param array<string, mixed> $meta
     * @param array<string, array<string, mixed>> $businessIndex
     * @param array<string, array{lat: float, lng: float}> $addressGeoIndex
     * @param array<string, true> $batchKeys
     * @param array<string, array<string, mixed>> $geoCache
     * @return array<string, mixed>|null
     */
    private function processImportRow(
        string $importId,
        array $row,
        array &$meta,
        array $businessIndex,
        array $addressGeoIndex,
        array &$batchKeys,
        array &$geoCache
    ): ?array {
        $nome = trim($row['nome'] ?? '');
        if ($nome !== '') {
            $row['razao'] = $nome;
            $row['fantasia'] = $nome;
        }

        $razao = trim($row['razao'] ?? $nome);
        $cep = preg_replace('/[^\d\-]/', '', $row['cep'] ?? '');
        $numero = Helper::resolveImportNumero($row);
        $row['numero'] = $numero;

        if ($razao === '' || $cep === '') {
            $meta['erros'] = (int) ($meta['erros'] ?? 0) + 1;
            return null;
        }
        if (strlen($cep) > 9) {
            $meta['erros'] = (int) ($meta['erros'] ?? 0) + 1;
            return null;
        }

        $businessKey = $this->importBusinessKeyFromRow($row, $cep, $numero);
        if ($businessKey === '') {
            $meta['erros'] = (int) ($meta['erros'] ?? 0) + 1;
            return null;
        }

        $existingHit = isset($businessIndex[$businessKey])
            ? $businessIndex[$businessKey]
            : null;

        if ($existingHit !== null && $this->importRowMatchesSnapshot($row, $cep, $numero, $existingHit['snapshot'] ?? [])) {
            $this->markImportSeenId($importId, (int) ($existingHit['id'] ?? 0), $meta);
            $meta['unchanged'] = (int) ($meta['unchanged'] ?? 0) + 1;
            $meta['ignorados'] = (int) ($meta['ignorados'] ?? 0) + 1;
            return null;
        }

        $knownId = ($existingHit !== null && !empty($existingHit['id'])) ? (int) $existingHit['id'] : null;
        $result = $this->resolveImportGeoResult(
            $importId,
            $row,
            $cep,
            $numero,
            $geoCache,
            $businessIndex,
            $addressGeoIndex,
            $meta
        );

        if (isset($result->error) || empty($result->latitude ?? null) || empty($result->longitude ?? null)) {
            if ($knownId !== null) {
                $this->markImportSeenId($importId, $knownId, $meta);
            }
            $meta['erros'] = (int) ($meta['erros'] ?? 0) + 1;
            if (empty($meta['erro_geocoding']) && isset($result->error)) {
                $meta['erro_geocoding'] = $result->error;
            }
            return null;
        }

        $resale = $this->buildImportResaleData($row, $result, $cep, null);

        if (!empty($batchKeys[$businessKey])) {
            if ($knownId !== null) {
                $this->markImportSeenId($importId, $knownId, $meta);
            } elseif (!empty($existingHit['id'])) {
                $this->markImportSeenId($importId, (int) $existingHit['id'], $meta);
            }
            $meta['ignorados'] = (int) ($meta['ignorados'] ?? 0) + 1;
            return null;
        }

        if ($knownId !== null) {
            $resale['id'] = $knownId;
        }

        if (isset($resale['id'])) {
            $this->markImportSeenId($importId, (int) $resale['id'], $meta);
        }

        $batchKeys[$businessKey] = true;
        $this->appendImportBatchKey($importId, $businessKey);

        return $resale;
    }

    /**
     * Reutiliza coordenadas da base ou do lote antes de consultar a API Google (custo).
     *
     * @param array<string, array<string, mixed>> $geoCache
     * @param array<string, array<string, mixed>> $businessIndex
     * @param array<string, array{lat: float, lng: float}> $addressGeoIndex
     * @param array<string, mixed> $meta
     */
    private function resolveImportGeoResult(
        string $importId,
        array $row,
        string $cep,
        string $numero,
        array &$geoCache,
        array $businessIndex,
        array $addressGeoIndex,
        array &$meta
    ) {
        $latCsv = trim((string) ($row['lat'] ?? $row['latitude'] ?? ''));
        $lngCsv = trim((string) ($row['lng'] ?? $row['longitude'] ?? ''));
        if ($latCsv !== '' && $lngCsv !== '' && is_numeric($latCsv) && is_numeric($lngCsv)) {
            $meta['geo_reused'] = (int) ($meta['geo_reused'] ?? 0) + 1;
            return (object) [
                'latitude'  => (float) $latCsv,
                'longitude' => (float) $lngCsv,
            ];
        }

        $businessKey = $this->importBusinessKeyFromRow($row, $cep, $numero);
        if ($businessKey !== '' && isset($businessIndex[$businessKey])) {
            $hit = $businessIndex[$businessKey];
            if ($this->isValidImportLatLng($hit['lat'] ?? null, $hit['lng'] ?? null)) {
                $meta['geo_reused'] = (int) ($meta['geo_reused'] ?? 0) + 1;
                return (object) [
                    'latitude'  => (float) $hit['lat'],
                    'longitude' => (float) $hit['lng'],
                ];
            }
        }

        $addressKey = $this->importAddressKey($cep, $numero);
        if ($addressKey !== '' && isset($addressGeoIndex[$addressKey])) {
            $coords = $addressGeoIndex[$addressKey];
            if ($this->isValidImportLatLng($coords['lat'] ?? null, $coords['lng'] ?? null)) {
                $meta['geo_reused'] = (int) ($meta['geo_reused'] ?? 0) + 1;
                return (object) [
                    'latitude'  => (float) $coords['lat'],
                    'longitude' => (float) $coords['lng'],
                ];
            }
        }

        $addressLine = $this->buildGeocodeAddressLine($row, $numero);
        $geoCacheKey = $cep . '|' . $numero . '|' . ($addressLine ?? '');
        $result = $this->getGeoFromImportCache($geoCache, $geoCacheKey, $cep, $numero, $addressLine, $meta);

        if ($addressKey !== ''
            && empty($result->error ?? null)
            && $this->isValidImportLatLng($result->latitude ?? null, $result->longitude ?? null)
        ) {
            $this->appendRuntimeGeoCache(
                $importId,
                $addressKey,
                (float) $result->latitude,
                (float) $result->longitude
            );
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $geoCache
     * @param array<string, mixed>|null $meta
     */
    private function getGeoFromImportCache(array &$geoCache, string $key, string $cep, string $numero, ?string $addressLine, ?array &$meta = null)
    {
        if (isset($geoCache[$key])) {
            return (object) $geoCache[$key];
        }

        $result = $this->getGeo($cep, $numero, $addressLine);
        if ($meta !== null) {
            $meta['geo_api_calls'] = (int) ($meta['geo_api_calls'] ?? 0) + 1;
        }
        $geoCache[$key] = [
            'latitude'  => $result->latitude ?? null,
            'longitude' => $result->longitude ?? null,
            'error'     => $result->error ?? null,
        ];

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{total: int, save: int, update: int, error?: string}
     */
    private function persistImportResultsChunk(array $rows): array
    {
        $save = [];
        $update = [];

        foreach ($rows as $r) {
            if (isset($r['id'])) {
                $update[] = $r;
            } else {
                $save[] = $r;
            }
        }

        try {
            $saved = !empty($save) ? $this->storage->bulkInsert($save) : 0;
            $updated = !empty($update) ? $this->storage->bulkUpdate($update) : 0;

            return [
                'total'  => $saved + $updated,
                'save'   => $saved,
                'update' => $updated,
            ];
        } catch (\Throwable $e) {
            return [
                'total'  => 0,
                'save'   => 0,
                'update' => 0,
                'error'  => $this->importExceptionMessage(
                    $e,
                    __('Erro ao salvar registros importados. Verifique se o arquivo resales.json não está corrompido.', 'busca-cep')
                ),
            ];
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return array{total: int, save: int, update: int, error?: string}
     */
    private function persistImportResults(array $state): array
    {
        $save = ['resales' => []];
        $update = ['resales' => []];

        foreach ($state['to_insert'] as $r) {
            if (isset($r['id'])) {
                $update['resales'][] = $r;
            } else {
                $save['resales'][] = $r;
            }
        }

        try {
            if (!empty($save['resales'])) {
                $this->storage->bulkInsert($save['resales']);
            }
            if (!empty($update['resales'])) {
                $this->storage->bulkUpdate($update['resales']);
            }
        } catch (\Throwable $e) {
            return [
                'total'  => 0,
                'save'   => 0,
                'update' => 0,
                'error'  => $this->importExceptionMessage(
                    $e,
                    __('Erro ao salvar registros importados. Verifique se o arquivo resales.json não está corrompido.', 'busca-cep')
                ),
            ];
        }

        return [
            'total'  => count($save['resales']) + count($update['resales']),
            'save'   => count($save['resales']),
            'update' => count($update['resales']),
        ];
    }

    /**
     * Chave de duplicata (lat, lng, especialidade, plano, CNPJ/CRM) — mesma regra de Storage::validate.
     */
    private function duplicateKeyFromResale(array $resale): string
    {
        $lat = round((float) ($resale['lat'] ?? 0), 5);
        $lng = round((float) ($resale['lng'] ?? 0), 5);
        $esp = mb_strtolower(trim((string) ($resale['especialidade'] ?? '')));
        $planoKey = Helper::normalizePlanoForDuplicate($resale['plano'] ?? '');
        $cnpjKey = Helper::normalizeCnpjCrmForDuplicate($resale['cnpj'] ?? '');

        return $lat . '|' . $lng . '|' . $esp . '|' . $planoKey . '|' . $cnpjKey;
    }

    /**
     * Índice de revendas existentes para evitar reler o JSON a cada linha da importação.
     *
     * @return array<string, int>
     */
    private function buildDuplicateIndex(array $rows): array
    {
        $index = [];
        foreach ($rows as $r) {
            $key = $this->duplicateKeyFromResale($r);
            $index[$key] = (int) ($r['id'] ?? 0);
        }

        return $index;
    }

    /**
     * Índices para reutilizar coordenadas e detectar registros idênticos sem chamar a API Google.
     *
     * @return array{dup: array<string, int>, business: array<string, array<string, mixed>>, address: array<string, array{lat: float, lng: float}>}
     */
    private function buildImportLookupIndexes(array $rows): array
    {
        $dup = [];
        $business = [];
        $address = [];

        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }

            $dup[$this->duplicateKeyFromResale($r)] = (int) ($r['id'] ?? 0);

            $lat = $r['lat'] ?? null;
            $lng = $r['lng'] ?? null;
            if ($this->isValidImportLatLng($lat, $lng)) {
                $addrKey = $this->importAddressKey((string) ($r['cep'] ?? ''), (string) ($r['numero'] ?? ''));
                if ($addrKey !== '' && !isset($address[$addrKey])) {
                    $address[$addrKey] = [
                        'lat' => (float) $lat,
                        'lng' => (float) $lng,
                    ];
                }
            }

            $bizKey = $this->importBusinessKeyFromResale($r);
            if ($bizKey === '') {
                continue;
            }

            $business[$bizKey] = [
                'id'       => (int) ($r['id'] ?? 0),
                'lat'      => $this->isValidImportLatLng($lat, $lng) ? (float) $lat : null,
                'lng'      => $this->isValidImportLatLng($lat, $lng) ? (float) $lng : null,
                'snapshot' => $this->importSnapshotFromResale($r),
            ];
        }

        return [
            'dup'      => $dup,
            'business' => $business,
            'address'  => $address,
        ];
    }

    private function importAddressKey(string $cep, string $numero): string
    {
        $cep8 = Helper::normalizarCep8Digitos(preg_replace('/\D/', '', $cep));
        $num = trim($numero);
        if ($cep8 === '' || $num === '') {
            return '';
        }

        return $cep8 . '|' . $num;
    }

    private function importBusinessKeyFromRow(array $row, string $cep, string $numero): string
    {
        return $this->importBusinessKeyFromFields(
            $cep,
            $numero,
            $row['plano'] ?? '',
            $row['especialidade'] ?? '',
            $row['cnpj'] ?? ''
        );
    }

    private function importBusinessKeyFromResale(array $resale): string
    {
        return $this->importBusinessKeyFromFields(
            (string) ($resale['cep'] ?? ''),
            (string) ($resale['numero'] ?? ''),
            $resale['plano'] ?? '',
            $resale['especialidade'] ?? '',
            $resale['cnpj'] ?? ''
        );
    }

    private function importBusinessKeyFromFields(string $cep, string $numero, $plano, $especialidade, $cnpj): string
    {
        $addrKey = $this->importAddressKey($cep, $numero);
        if ($addrKey === '') {
            return '';
        }

        $planoKey = Helper::normalizePlanoForDuplicate((string) $plano);
        $esp = mb_strtolower(trim((string) $especialidade));
        $cnpjKey = Helper::normalizeCnpjCrmForDuplicate((string) $cnpj);

        return $addrKey . '|' . $planoKey . '|' . $esp . '|' . $cnpjKey;
    }

    /**
     * @return array<string, string>
     */
    private function importSnapshotFromResale(array $resale): array
    {
        $nome = trim($resale['razao'] ?? $resale['fantasia'] ?? '');

        return $this->importSnapshotFromFields(
            $nome,
            (string) ($resale['plano'] ?? ''),
            (string) ($resale['especialidade'] ?? ''),
            (string) ($resale['cnpj'] ?? ''),
            (string) ($resale['whatsapp'] ?? ''),
            (string) ($resale['telefone'] ?? ''),
            (string) ($resale['horario'] ?? ''),
            (string) ($resale['cep'] ?? ''),
            (string) ($resale['numero'] ?? ''),
            (string) ($resale['rua'] ?? ''),
            (string) ($resale['bairro'] ?? ''),
            (string) ($resale['municipio'] ?? ''),
            (string) ($resale['estado'] ?? ''),
            (string) ($resale['status'] ?? 'ativo')
        );
    }

    /**
     * @return array<string, string>
     */
    private function importSnapshotFromRow(array $row, string $cep, string $numero): array
    {
        $nome = trim($row['nome'] ?? $row['razao'] ?? $row['fantasia'] ?? '');

        return $this->importSnapshotFromFields(
            $nome,
            (string) ($row['plano'] ?? ''),
            (string) ($row['especialidade'] ?? ''),
            (string) ($row['cnpj'] ?? ''),
            (string) ($row['whatsapp'] ?? ''),
            (string) ($row['telefone'] ?? ''),
            (string) ($row['horario'] ?? ''),
            $cep,
            $numero,
            (string) ($row['rua'] ?? ''),
            (string) ($row['bairro'] ?? ''),
            (string) ($row['municipio'] ?? ''),
            (string) ($row['estado'] ?? ''),
            (string) ($row['status'] ?? 'ativo')
        );
    }

    /**
     * @return array<string, string>
     */
    private function importSnapshotFromFields(
        string $nome,
        string $plano,
        string $especialidade,
        string $cnpj,
        string $whatsapp,
        string $telefone,
        string $horario,
        string $cep,
        string $numero,
        string $rua,
        string $bairro,
        string $municipio,
        string $estado,
        string $status
    ): array {
        return [
            'nome'           => mb_strtolower(trim($nome)),
            'plano'          => Helper::normalizePlanoForDuplicate($plano),
            'especialidade'  => mb_strtolower(trim($especialidade)),
            'cnpj'           => Helper::normalizeCnpjCrmForDuplicate($cnpj),
            'whatsapp'       => preg_replace('/\D/', '', $whatsapp),
            'telefone'       => preg_replace('/\D/', '', $telefone),
            'horario'        => mb_strtolower(trim($horario)),
            'cep'            => Helper::normalizarCep8Digitos(preg_replace('/\D/', '', $cep)),
            'numero'         => trim($numero),
            'rua'            => mb_strtolower(trim($rua)),
            'bairro'         => mb_strtolower(trim($bairro)),
            'municipio'      => mb_strtolower(trim($municipio)),
            'estado'         => mb_strtolower(trim($estado)),
            'status'         => mb_strtolower(trim($status)),
        ];
    }

    /**
     * @param array<string, string> $snapshot
     */
    private function importRowMatchesSnapshot(array $row, string $cep, string $numero, array $snapshot): bool
    {
        if (empty($snapshot)) {
            return false;
        }

        return $this->importSnapshotFromRow($row, $cep, $numero) === $snapshot;
    }

    private function isValidImportLatLng($lat, $lng): bool
    {
        if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }

        $latF = (float) $lat;
        $lngF = (float) $lng;

        return $latF != 0.0 && $lngF != 0.0 && !Helper::isPontoCentroideBrasil($latF, $lngF);
    }

    /**
     * Monta linha de endereço para geocode (importação) quando o CSV traz logradouro completo.
     */
    private function buildGeocodeAddressLine(array $row, string $numero): ?string
    {
        $rua = trim((string) ($row['rua'] ?? ''));
        $bairro = trim((string) ($row['bairro'] ?? ''));
        $municipio = trim((string) ($row['municipio'] ?? ''));
        $estado = trim((string) ($row['estado'] ?? ''));
        if ($rua === '' || $municipio === '' || $estado === '') {
            return null;
        }

        $num = trim($numero);
        $numUpper = mb_strtoupper($num);
        if ($num === '' || $numUpper === 'S/N' || $numUpper === 'SN') {
            $parts = [$rua];
        } else {
            $parts = [$rua, $num];
        }
        if ($bairro !== '') {
            $parts[] = $bairro;
        }
        $parts[] = $municipio;
        $parts[] = $estado;
        $parts[] = 'Brazil';

        return implode(', ', $parts);
    }

    /**
     * Texto vindo do formulário ou CSV — nunca preencher com retorno do geocode.
     */
    private function userProvidedText(?string $v): ?string
    {
        $t = trim((string) ($v ?? ''));

        return $t !== '' ? $t : null;
    }

    /**
     * Obtém coordenadas geográficas de um CEP usando Google Geocoding API.
     *
     * @param string|null $structuredAddress Endereço completo (ex.: import CSV) — tentativa antes do CEP isolado.
     */
    public function getGeo($cep, $numero = null, ?string $structuredAddress = null)
    {
        $row = $this->storage->getConfig('*', ['id' => 1]);
        $key = (!empty($row) && isset($row[0]['token'])) ? $row[0]['token'] : '';

        if (empty($key)) {
            return (object) ['error' => 'Token da API Google não configurado. Configure em Rede Credenciada > Configurações.'];
        }

        $cepDigits = preg_replace('/[^\d]/', '', $cep);
        if (strlen($cepDigits) < 8) {
            $cepDigits = str_pad($cepDigits, 8, '0', STR_PAD_LEFT);
        }

        if ($structuredAddress !== null && trim($structuredAddress) !== '') {
            $query = http_build_query(['address' => trim($structuredAddress), 'key' => $key, 'region' => 'br']);
            $resAddr = $this->helper->send('https://maps.googleapis.com/maps/api/geocode/json?' . $query);
            if (($resAddr->status ?? '') === 'OK' && !empty($resAddr->results[0] ?? null)) {
                $parsedAddr = $this->parseGeoResult($resAddr, $cepDigits);
                if (empty($parsedAddr->error ?? null)) {
                    $latA = isset($parsedAddr->latitude) ? (float) $parsedAddr->latitude : null;
                    $lngA = isset($parsedAddr->longitude) ? (float) $parsedAddr->longitude : null;
                    if ($latA !== null && $lngA !== null && !Helper::isPontoCentroideBrasil($latA, $lngA)) {
                        return $parsedAddr;
                    }
                }
            }
        }

        $result = null;

        // 1. Tenta com components (mais confiável para CEP Brasil)
        $components = "country:BR|postal_code:{$cepDigits}";
        $query = http_build_query(['components' => $components, 'key' => $key]);
        $result = $this->helper->send("https://maps.googleapis.com/maps/api/geocode/json?$query");

        // 2. Fallback: address com CEP formatado
        if ($result->status === 'ZERO_RESULTS' || empty($result->results[0] ?? null)) {
            $cepFormatado = strlen($cepDigits) === 8
                ? substr($cepDigits, 0, 5) . '-' . substr($cepDigits, 5)
                : $cep;
            $address = $numero ? "{$cepFormatado}, {$numero}, Brazil" : "{$cepFormatado}, Brazil";
            $query = http_build_query(['address' => $address, 'key' => $key, 'region' => 'br']);
            $result = $this->helper->send("https://maps.googleapis.com/maps/api/geocode/json?$query");
        }

        // 3. Fallback: apenas CEP
        if ($result->status === 'ZERO_RESULTS' || empty($result->results[0] ?? null)) {
            $cepFormatado = strlen($cepDigits) === 8
                ? substr($cepDigits, 0, 5) . '-' . substr($cepDigits, 5)
                : $cep;
            $query = http_build_query(['address' => "{$cepFormatado}, Brazil", 'key' => $key, 'region' => 'br']);
            $result = $this->helper->send("https://maps.googleapis.com/maps/api/geocode/json?$query");
        }

        if ($result === null || !is_object($result)) {
            return (object) ['error' => 'Resposta inválida da API de geolocalização.'];
        }

        $parsed = $this->parseGeoResult($result, $cepDigits);
        if (!empty($parsed->error ?? null)) {
            return $parsed;
        }

        $lat = isset($parsed->latitude) ? (float) $parsed->latitude : null;
        $lng = isset($parsed->longitude) ? (float) $parsed->longitude : null;
        if ($lat !== null && $lng !== null && Helper::isPontoCentroideBrasil($lat, $lng)) {
            $cep8n = Helper::normalizarCep8Digitos($cepDigits);
            $ufFaixa = Helper::ufFromCepDigits8($cep8n);
            $capital = Helper::capitalPorUf($ufFaixa);
            if ($capital !== '') {
                $cepFmt = strlen($cepDigits) >= 8
                    ? substr($cepDigits, 0, 5) . '-' . substr($cepDigits, 5)
                    : $cepDigits;
                $addressRetry = "{$cepFmt}, {$capital}, {$ufFaixa}, Brazil";
                $qRetry = http_build_query(['address' => $addressRetry, 'key' => $key, 'region' => 'br']);
                $rRetry = $this->helper->send('https://maps.googleapis.com/maps/api/geocode/json?' . $qRetry);
                if (($rRetry->status ?? '') === 'OK' && !empty($rRetry->results[0])) {
                    $parsed2 = $this->parseGeoResult($rRetry, $cepDigits);
                    if (empty($parsed2->error ?? null)) {
                        $lat2 = (float) ($parsed2->latitude ?? 0);
                        $lng2 = (float) ($parsed2->longitude ?? 0);
                        if (!Helper::isPontoCentroideBrasil($lat2, $lng2)) {
                            return $parsed2;
                        }
                    }
                }
            }
        }

        return $parsed;
    }

    /**
     * Retorna o nome da revenda (razao/fantasia unificados).
     */
    private function getNome(array $row): string
    {
        return trim($row['razao'] ?? $row['fantasia'] ?? '');
    }

    /**
     * Monta array de dados de uma revenda a partir dos parâmetros e resultado de geocoding.
     */
    private function buildResaleData(array $param, object $result, string $cep): array
    {
        $nome = trim($param['nome'] ?? '');
        $razao = $param['razao'] ?? $nome;
        $fantasia = $param['fantasia'] ?? $nome;
        return [
            'razao'         => $razao ?: null,
            'fantasia'      => $fantasia ?: null,
            'plano'         => $param['plano'] ?? null,
            'especialidade' => $param['especialidade'] ?? null,
            'cnpj'          => $param['cnpj'] ?? null,
            'whatsapp'      => $param['whatsapp'] ?? null,
            'horario'       => $param['horario'] ?? null,
            'telefone'      => $param['telefone'] ?? null,
            'cep'           => $cep,
            'rua'           => $this->userProvidedText($param['rua'] ?? null),
            'numero'        => $param['numero'] ?? null,
            'bairro'        => $this->userProvidedText($param['bairro'] ?? null),
            'municipio'     => $this->userProvidedText($param['municipio'] ?? null),
            'estado'        => $this->userProvidedText($param['estado'] ?? null),
            'pais'          => $this->userProvidedText($param['pais'] ?? null),
            'lat'           => $result->latitude ?? null,
            'lng'           => $result->longitude ?? null,
            'status'        => $param['status'] ?? 'ativo',
            'date'          => date('d-m-Y'),
            'token'         => $param['token'] ?? null,
        ];
    }

    /**
     * Monta dados de revenda para importação.
     * Geocode alimenta somente lat/lng; endereço e demais colunas vêm exclusivamente da planilha.
     */
    private function buildImportResaleData(array $resale, object $result, string $cep, ?string $token): array
    {
        return [
            'razao'         => $resale['razao'] ?? null,
            'fantasia'      => $resale['fantasia'] ?? null,
            'plano'         => $resale['plano'] ?? null,
            'especialidade' => $resale['especialidade'] ?? null,
            'cnpj'          => $resale['cnpj'] ?? null,
            'whatsapp'      => $resale['whatsapp'] ?? null,
            'horario'       => $resale['horario'] ?? null,
            'telefone'      => $resale['telefone'] ?? null,
            'cep'           => $cep,
            'numero'        => $resale['numero'],
            'rua'           => $this->userProvidedText($resale['rua'] ?? null),
            'bairro'        => $this->userProvidedText($resale['bairro'] ?? null),
            'municipio'     => $this->userProvidedText($resale['municipio'] ?? null),
            'estado'        => $this->userProvidedText($resale['estado'] ?? null),
            'pais'          => $this->userProvidedText($resale['pais'] ?? null),
            'lat'       => round((float) ($result->latitude ?? 0), 7),
            'lng'       => round((float) ($result->longitude ?? 0), 7),
            'status'    => $resale['status'] ?? 'ativo',
            'date'      => date('d-m-Y'),
            'token'     => $token,
        ];
    }

    /**
     * Escolhe o resultado do Geocode mais compatível com o CEP (vários resultados são comuns em CEPs genéricos como 40000-000).
     */
    private function pickBestGeocodeResult(array $results, string $expectedUf, string $cepDigitsNorm): ?object
    {
        if ($results === [] || !isset($results[0])) {
            return null;
        }

        $cep8 = Helper::normalizarCep8Digitos($cepDigitsNorm);
        $wantUf = strtoupper($expectedUf);
        $cepDigitsOnly = preg_replace('/\D/', '', $cep8);

        if ($wantUf !== '') {
            foreach ($results as $r) {
                $uf = strtoupper($this->extractUfFromAddressComponents($r->address_components ?? []));
                if ($uf !== $wantUf) {
                    continue;
                }
                $pc = $this->extractPostalCodeDigits($r->address_components ?? []);
                if ($pc !== '' && $this->postalMatchesCepDigits($pc, $cepDigitsOnly)) {
                    return $r;
                }
            }
            foreach ($results as $r) {
                $uf = strtoupper($this->extractUfFromAddressComponents($r->address_components ?? []));
                if ($uf === $wantUf) {
                    return $r;
                }
            }
        }

        foreach ($results as $r) {
            $pc = $this->extractPostalCodeDigits($r->address_components ?? []);
            if ($pc !== '' && $this->postalMatchesCepDigits($pc, $cepDigitsOnly)) {
                return $r;
            }
        }

        return $results[0];
    }

    private function extractUfFromAddressComponents(array $components): string
    {
        foreach ($components as $component) {
            foreach ($component->types ?? [] as $type) {
                if ($type === 'administrative_area_level_1') {
                    return trim((string) ($component->short_name ?? ''));
                }
            }
        }

        return '';
    }

    private function extractPostalCodeDigits(array $components): string
    {
        foreach ($components as $component) {
            foreach ($component->types ?? [] as $type) {
                if ($type === 'postal_code') {
                    return preg_replace('/\D/', '', (string) ($component->short_name ?? ''));
                }
            }
        }

        return '';
    }

    private function postalMatchesCepDigits(string $postalDigits, string $cepDigitsOnly): bool
    {
        if ($cepDigitsOnly === '' || strlen($cepDigitsOnly) < 8) {
            return false;
        }
        if ($postalDigits === $cepDigitsOnly) {
            return true;
        }
        if (strlen($postalDigits) >= 5 && strpos($cepDigitsOnly, $postalDigits) === 0) {
            return true;
        }
        if (strlen($cepDigitsOnly) >= 5 && strpos($postalDigits, substr($cepDigitsOnly, 0, 5)) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Processa resposta da API de geocoding do Google.
     *
     * @param string $cepDigits 8 dígitos do CEP (só números) para escolher o resultado correto entre vários.
     */
    private function parseGeoResult(object $result, string $cepDigits = ''): object
    {
        $json = [];

        if ($result->status !== 'OK' && $result->status !== 'ZERO_RESULTS') {
            $status = $result->status ?? '';
            $msg = $result->error_message ?? '';
            if ($status === 'REQUEST_DENIED') {
                $json['error'] = 'Chave da API Google inválida ou não autorizada. ' . ($msg ?: 'Verifique em Rede Credenciada > Configurações > Testar token.');
            } elseif ($status === 'OVER_QUERY_LIMIT' || $status === 'OVER_DAILY_LIMIT') {
                $json['error'] = 'Limite de requisições da API Google excedido. ' . ($msg ?: 'Verifique o faturamento no Google Cloud.');
            } elseif ($status === 'INVALID_REQUEST') {
                $json['error'] = 'Endereço inválido para geolocalização.';
            } else {
                $json['error'] = 'Erro na API de geolocalização: ' . ($msg ?: $status ?: 'Erro desconhecido');
            }
            return (object) $json;
        }

        if ($result->status === 'ZERO_RESULTS' || empty($result->results[0] ?? null)) {
            $json['error'] = 'Erro na geolocalização, ponto não válido';
            return (object) $json;
        }

        $results = $result->results;
        if (!is_array($results)) {
            $json['error'] = 'Erro na geolocalização, ponto não válido';
            return (object) $json;
        }

        $cep8 = Helper::normalizarCep8Digitos($cepDigits);
        $expectedUf = Helper::ufFromCepDigits8($cep8);
        $best = $this->pickBestGeocodeResult($results, $expectedUf, $cepDigits);

        if ($best === null) {
            $json['error'] = 'Erro na geolocalização, ponto não válido';
            return (object) $json;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $loc = $best->geometry->location ?? null;
            error_log(sprintf(
                '[busca-cep] geocode CEP_digits=%s esperado_UF=%s escolhido_UF=%s lat=%s lng=%s n_resultados=%d',
                $cep8,
                $expectedUf,
                $this->extractUfFromAddressComponents($best->address_components ?? []),
                $loc ? (string) $loc->lat : 'n/a',
                $loc ? (string) $loc->lng : 'n/a',
                count($results)
            ));
        }

        $components = $best->address_components ?? [];

        foreach ($components as $component) {
            foreach ($component->types ?? [] as $type) {
                switch ($type) {
                    case 'postal_code':
                        $json['cep'] = $component->short_name;
                        break;
                    case 'route':
                        $json['rua'] = $component->short_name;
                        break;
                    case 'sublocality_level_1':
                        $json['bairro'] = $component->short_name;
                        break;
                    case 'administrative_area_level_2':
                        $json['municipio'] = $component->short_name;
                        break;
                    case 'administrative_area_level_1':
                        $json['estado'] = $component->short_name;
                        break;
                    case 'country':
                        $json['pais'] = $component->short_name;
                        break;
                }
            }
        }

        $location = $best->geometry->location ?? null;

        if ($location) {
            $json['latitude'] = $location->lat;
            $json['longitude'] = $location->lng;
        }

        return (object) $json;
    }
}
