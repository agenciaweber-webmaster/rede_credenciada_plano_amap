# Rede Credenciada (busca-cep)

**Versão interna:** 6.5.2  
**WordPress mínimo:** 6.5  
**Namespace REST:** `resales/v1/json`

Plugin WordPress para cadastro, importação em massa e consulta pública da rede credenciada por CEP, com geocodificação via Google Maps API.

---

## Índice

1. [Visão geral](#visão-geral)
2. [Estrutura do plugin](#estrutura-do-plugin)
3. [Painel administrativo](#painel-administrativo)
4. [Importação CSV](#importação-csv)
5. [Modo sincronização](#modo-sincronização)
6. [Geocodificação e reutilização de coordenadas](#geocodificação-e-reutilização-de-coordenadas)
7. [Consulta pública (frontend)](#consulta-pública-frontend)
8. [API REST](#api-rest)
9. [Armazenamento de dados](#armazenamento-de-dados)
10. [Changelog recente (6.4.x – 6.5.x)](#changelog-recente-64x--65x)

---

## Visão geral

O plugin permite:

- Cadastrar, editar e excluir prestadores no admin WordPress
- Importar milhares de registros via CSV sem estourar timeout do servidor
- Exportar a base completa em CSV
- Exibir no mapa público os credenciados próximos a um CEP informado

Cada **especialidade** atendida deve ter um cadastro próprio (não agrupar várias especialidades em um único registro).

---

## Estrutura do plugin

```
busca-cep/
├── busca-cep.php              # Bootstrap e versão (BUSCACEP_VERSION)
├── assets/
│   ├── css/admin-styles.css   # Estilos do painel admin
│   ├── css/frontend-styles.css
│   ├── js/admin-scripts.js    # Admin: CRUD, importação em lotes, contador
│   └── js/frontend-scripts.js # Mapa e busca por CEP
├── components/storage/
│   ├── resales.json           # Base de credenciados
│   └── config.json            # Token Google API
├── includes/
│   ├── Controllers/
│   │   ├── AdminConfig.php    # Menus, enqueue de assets
│   │   ├── Resale.php         # CRUD, importação, geocodificação
│   │   ├── Consulta.php       # Listagem admin e busca pública
│   │   └── RestApi.php        # Rotas REST
│   ├── Models/Storage.php     # Persistência JSON (bulk insert/update/delete)
│   └── Helpers/Helper.php     # Normalização CEP, plano, CNPJ, número na importação
└── views/
    ├── admin-display.php      # Tela principal da rede credenciada
    └── map-display.php        # Shortcode / página pública do mapa
```

Arquivos temporários de importação: `wp-content/uploads/busca-cep-imports/` (meta, linhas JSONL, índices, IDs vistos no sync).

---

## Painel administrativo

**Menu:** Rede Credenciada → Revendas  
**Configurações:** Rede Credenciada → Configurações (token Google API)

### Ações disponíveis

| Botão        | Função                                      |
|-------------|----------------------------------------------|
| Cadastrar   | Abre modal para novo credenciado             |
| Exportar    | Download CSV com todos os registros          |
| Importar CSV| Upload e importação em lotes                 |

### Interface (v6.5.x)

- **Total de cadastros:** exibido à direita, logo acima da tabela; atualiza após listagem, CRUD e importação.
- **Tabela:** rolagem horizontal apenas dentro da área da tabela (a página não ganha barra lateral).
- **Mensagens:** sucesso/erro da importação ficam visíveis até o usuário fechar (×) ou recarregar a página. Ações de cadastro/edição/exclusão usam modal com botão Fechar.
- **Modo sincronização:** checkbox padrão do WordPress ao lado do botão Importar; ao marcar, pede confirmação antes de iniciar.
- **Pesquisa:** filtro em tempo real na tabela (campo Pesquisar).

---

## Importação CSV

### Formato do arquivo

- **Encoding:** UTF-8 (com ou sem BOM)
- **Separador:** vírgula ou ponto e vírgula (detectado automaticamente)
- **Formato:** salvar planilha Excel como **CSV UTF-8** antes do upload

### Colunas esperadas

| Coluna        | Obrigatório | Observação |
|---------------|-------------|------------|
| nome          | Sim*        | Ou `razao` / `fantasia` |
| plano         | Sim         | Ex.: AMO Médico, AMAP Médico |
| especialidade | Sim         | Uma por linha |
| cnpj/crm      | Não         | Alias: `cnpj_crm` → `cnpj` |
| whatsapp      | Não         | |
| telefone      | Não         | |
| horario       | Não         | |
| cep           | Sim         | |
| rua           | Não         | Usada na geocodificação |
| numero        | Condicional | Ver regra abaixo |
| bairro        | Não         | |
| municipio     | Não         | |
| estado        | Não         | |
| pais          | Não         | |
| status        | Não         | Padrão: `ativo` |
| lat / lng     | Não         | Se presentes, evita chamada à API Google |

\* Nome/razão/fantasia e CEP são obrigatórios para processar a linha.

### Resolução do número (`Helper::resolveImportNumero`)

Muitas planilhas deixam a coluna `numero` vazia com o valor na coluna `rua`. O plugin:

1. Usa `numero` se preenchido
2. Tenta extrair da `rua` (padrões: `N. 10`, `nº 123`, `numero 45`, etc.)
3. Se não encontrar, usa **`S/N`** para permitir geocodificação e cadastro

### Fluxo em lotes (evita timeout)

| Etapa | Endpoint | Descrição |
|-------|----------|-----------|
| 1 | `POST /upload_file/init` | Recebe CSV, grava `.rows.jsonl`, cria índices, retorna `import_id` e `total` |
| 2 | `POST /upload_file/process` | Processa **100 linhas** por requisição (`IMPORT_BATCH_SIZE`) |
| 2… | Repetir process | Até `finished: true` |

A interface admin chama `process` automaticamente em sequência e exibe barra de progresso.

### Contadores na resposta final

| Campo | Significado |
|-------|-------------|
| `total_saved` | Inserções + atualizações gravadas |
| `unchanged` | Linhas idênticas à base (sem API Google) |
| `ignorados` | Duplicatas na planilha ou inalteradas contadas como ignoradas |
| `erros` | Linhas inválidas ou falha de geocodificação |
| `geo_reused` | Coordenadas reutilizadas da base ou do lote |
| `geo_api_calls` | Chamadas à API Google nesta importação |
| `removed` | Excluídos no modo sincronização |
| `record_count` | Total de registros na base após importação |

### Identificação de duplicatas na importação

- **Chave de negócio:** `CEP8|numero|plano|especialidade|cnpj` — define se a linha atualiza ou insere o mesmo credenciado.
- **Duplicata no mesmo arquivo:** segunda linha com a mesma chave de negócio é ignorada (`ignorados`).
- Linhas repetidas na planilha **não** geram cadastros extras; o total na base tende a ser menor que o número de linhas do CSV.

### Persistência

- `Storage::bulkInsert()` / `bulkUpdate()` — uma leitura/gravação do JSON por lote
- `Storage::bulkDelete()` — exclusão em massa (modo sync)

---

## Modo sincronização

Checkbox **“Modo sincronização (excluir cadastros ausentes na planilha)”** no formulário de importação.

### Comportamento

1. No **init**, grava todos os IDs existentes em `.existing-ids.json`
2. Durante o processamento, marca como **“vistos”** os registros:
   - idênticos à base (`unchanged`)
   - atualizados ou reconhecidos pela chave de negócio
   - presentes na planilha mas com erro de geocodificação (evita apagar quem estava no CSV)
3. Ao **finalizar com sucesso**, remove da base os IDs que existiam antes e **não** foram vistos

### Proteções

- Só exclui se a importação terminou por completo (`processed >= total`)
- Só exclui se houve pelo menos 1 registro gravado ou inalterado
- Só exclui se existirem IDs marcados como vistos
- Pedido de confirmação no navegador antes de iniciar

Com sync desmarcado, a importação apenas insere/atualiza — **não remove** cadastros ausentes na planilha.

---

## Geocodificação e reutilização de coordenadas

Ordem de resolução em cada linha (`resolveImportGeoResult`):

1. Colunas `lat`/`lng` (ou `latitude`/`longitude`) no CSV
2. Coordenadas já salvas na base para a mesma chave de negócio
3. Mesmo `CEP + número` já geocodificado na base
4. Cache do lote atual (`runtime-geo.jsonl`)
5. API Google Geocoding (incrementa `geo_api_calls`)

Endereço estruturado para geocode: rua, número (ou só rua quando `S/N`), bairro, município, estado. Fallbacks: CEP + número, depois só CEP.

**Configuração:** token em *Rede Credenciada → Configurações*.

---

## Consulta pública (frontend)

- Busca credenciados próximos ao CEP informado
- **Raio:** 30 km (`Consulta::RAIO_BUSCA_KM`)
- Filtro opcional por especialidade
- Shortcode / template do tema `rede_credenciada`

---

## API REST

Base: `/wp-json/resales/v1/json`

| Método | Rota | Descrição |
|--------|------|-----------|
| POST | `/create` | Criar credenciado |
| POST | `/update` | Atualizar |
| POST | `/delete` | Excluir |
| POST | `/config` | Salvar token Google |
| POST | `/upload_file/init` | Iniciar importação CSV |
| POST | `/upload_file/process` | Processar lote (`import_id`) |
| POST | `/upload_file` | Legado: init + process em loop |
| GET | `/export` | Exportar CSV |
| GET | `/getall` | Listagem admin (`html` + `count`) |
| GET | `/getToken` | Token configurado |
| GET | `/getDetails/{id}/{edit\|delete}` | Detalhes para modal |
| GET | `/consult/{cep}` | Busca pública (?especialidade=) |

---

## Armazenamento de dados

| Arquivo | Conteúdo |
|---------|----------|
| `components/storage/resales.json` | Array JSON de credenciados (id, nome, plano, especialidade, endereço, lat, lng, status, …) |
| `components/storage/config.json` | Token da API Google |

IDs são sequenciais; após exclusões o plugin usa o maior ID existente + 1 (`getMaxResaleId`), nunca `count()` de linhas.

---

## Changelog recente (6.4.x – 6.5.x)

### 6.4.x – Importação e performance

- Importação em lotes (`init` + `process`), 100 linhas por requisição
- Gravação incremental por lote (`bulkInsert` / `bulkUpdate`)
- Índices de importação: business, address-geo, dup; cache runtime de coordenadas
- Reutilização de coordenadas (base + CEP/número + lote) para reduzir custo Google
- Detecção de linhas idênticas (`unchanged`) sem chamar API
- Modo sincronização com exclusão de registros não vistos na planilha
- `resolveImportNumero`: extrai número da rua ou usa S/N
- Deduplicação na importação por chave de negócio (não só lat/lng)
- Raio de busca pública: 30 km
- Remoção de `session_start()` desnecessário no construtor de `Resale`

### 6.5.x – Admin e UX

- **Total de cadastros** acima da tabela (alinhado à direita)
- Mensagens persistentes com botão fechar; modal de ações sem auto-dismiss
- Resposta `/getall` com `{ html, count }`
- Mensagem final de importação com `record_count` na base
- Ordem dos botões: Cadastrar → Exportar → Importar CSV
- Tabela com scroll horizontal interno (sem scroll na página inteira)
- Checkbox de sync: padrão WordPress, sem anel azul ao clicar; ícone de marcação ampliado
- Margem reduzida entre total de cadastros e tabela

---

## Observações para planilhas grandes

- O total na base após importação **pode ser menor** que o número de linhas do CSV porque:
  - Linhas duplicadas (mesma chave de negócio) são ignoradas
  - Linhas sem CEP ou sem nome falham validação
  - Falhas pontuais de geocodificação incrementam `erros`
- Para alinhar a base à planilha, use **modo sincronização** e confira a mensagem final (`record_count`, `erros`, `ignorados`, `removed`).
- Planilhas Excel devem ser exportadas como **CSV UTF-8** antes do upload.

---

*Documentação atualizada em junho/2026 — versão do plugin 6.5.2.*
