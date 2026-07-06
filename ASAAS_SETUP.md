# Ativar o pagamento PIX real (Asaas) — Créditos do Candidato

A tela "Minha Carteira" do candidato (compra de créditos via PIX) está
funcionando hoje em **modo mock**: gera um QR Code fake e confirma o
pagamento sozinho ~8 segundos depois, sem depender de nenhum gateway real.
Isso deixa toda a estrutura (banco, rotas, frontend) pronta — falta só
plugar uma conta Asaas de verdade.

## O que já está pronto

- Tabela `creditos_compras` (registro de cada tentativa de compra: valor,
  CPF/nome informados, status, dados do PIX).
- `App\Services\AsaasService` — chama a API real do Asaas quando
  `ASAAS_API_KEY` está definida; caso contrário, cai automaticamente no
  modo mock. Nenhuma outra parte do código precisa mudar.
- `CandidatoCreditoController::comprar()` — cria a cobrança e devolve o
  QR Code pro frontend.
- `CandidatoCreditoController::statusCompra()` — endpoint de polling que o
  frontend chama a cada alguns segundos pra saber se o PIX foi pago.
- `AsaasWebhookController` — endpoint público `POST /api/webhooks/asaas`
  pra receber a confirmação em tempo real do Asaas (evita depender só do
  polling, uma vez configurado).

## Passo a passo para ativar

1. **Criar conta no Asaas** (https://www.asaas.com). Para testar antes de ir
   pra produção, use o sandbox: https://sandbox.asaas.com.

2. **Pegar a API Key**: no painel Asaas, em
   `Integrações → Chave de API` (ou `Configurações → Integrações`).

3. **Configurar o `.env`** da API:
   ```
   ASAAS_API_KEY=sua_chave_aqui
   ASAAS_BASE_URL=https://api-sandbox.asaas.com/v3   # trocar para https://api.asaas.com/v3 em produção
   ASAAS_WEBHOOK_TOKEN=um_token_secreto_qualquer      # opcional, mas recomendado
   ```
   Assim que `ASAAS_API_KEY` deixar de estar vazia, o `AsaasService` passa a
   chamar a API real automaticamente — nada mais precisa ser alterado no
   código.

4. **Rodar a migration** (se ainda não rodou):
   ```
   php artisan migrate
   ```

5. **Configurar o Webhook no painel do Asaas** (opcional, mas recomendado
   pra não depender só do polling):
   - URL: `https://SEU_DOMINIO_DA_API/api/webhooks/asaas`
   - Eventos: `PAYMENT_RECEIVED` e `PAYMENT_CONFIRMED`
   - Se você definiu `ASAAS_WEBHOOK_TOKEN`, cole o mesmo valor no campo de
     token/autenticação do webhook no painel Asaas (é enviado no header
     `asaas-access-token` e validado em `AsaasWebhookController`).

6. **Testar**: no sandbox do Asaas, cobranças PIX podem ser confirmadas
   manualmente pelo próprio painel (simulando o pagamento), o que dispara o
   webhook e credita os créditos automaticamente.

## O que NÃO precisa ser mexido

- Frontend (`candidate/src/pages/Creditos.jsx`) — já consome o formato de
  resposta correto (`pix.qrCode`, `pix.qrCodeImage`, etc.) e já faz o
  polling; não depende de saber se está em modo mock ou real.
- Rotas — já registradas (`routes/api.php`).
- Lógica de crédito de saldo — `CandidatoCreditoController::confirmarPagamento()`
  é chamada tanto pelo polling quanto pelo webhook, sempre com proteção
  contra duplicidade (não credita duas vezes a mesma compra).
