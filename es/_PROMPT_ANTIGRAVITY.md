# PROMPT PARA O ANTIGRAVITY — Tradução da oferta PT-BR → ES (LATAM)

Cole o texto abaixo no Antigravity. Ele explica exatamente o que fazer.

---

## CONTEXTO
Estou lançando uma versão em **espanhol (LATAM neutro)** de uma oferta que já existe em português.
Todos os arquivos da versão espanhola estão **NESTA pasta e SOMENTE nesta pasta**:

```
estrutura do site/es/
```

NÃO toque em NENHUM arquivo fora da pasta `es/`. A versão em português (arquivos na pasta pai)
NÃO pode ser alterada de forma alguma.

## SUA TAREFA
Traduzir **apenas o TEXTO VISÍVEL ao usuário** de português para **espanhol neutro (LATAM)**
nos seguintes arquivos dentro de `es/`:

- `es/vendas.html`        (página de vendas / VSL)
- `es/clean.html`         (página branca / white page)
- `es/aceleradorroteirodivino/index.html`  (página de upsell)

O `es/index.php` é o roteador (cloaker) — ver instrução específica no final.

## REGRAS ABSOLUTAS (não quebrar o site)
1. Traduza SOMENTE o texto que o usuário lê na tela (títulos, parágrafos, botões, labels,
   placeholders, mensagens, textos de countdown, depoimentos, FAQ etc.).
2. NÃO altere, NÃO renomeie, NÃO traduza:
   - tags HTML, atributos, `id`, `class`, `name`, `data-*`
   - qualquer coisa dentro de `<script>` … `</script>` (JavaScript)
   - qualquer coisa dentro de `<style>` … `</style>` (CSS)
   - URLs, `href`, `src`, caminhos de arquivo (ex.: `Imagens/logo.png`)
   - IDs de pixel, tokens, scripts de tracking (Meta Pixel, UTMify), cloaker
   - variáveis, funções, chaves de configuração
3. Mantenha os caminhos de imagem como estão (`Imagens/...`) — as imagens já estão nesta pasta.
4. Mantenha a estrutura, o layout e a formatação idênticos. Só troca o idioma do texto.
5. Espanhol NEUTRO LATAM (evite regionalismos de um país só). Tom vendedor/persuasivo,
   mantendo a mesma intenção e emoção do original.

## NOMES TRAVADOS (usar EXATAMENTE assim — já batem com a VSL)
- Produto principal: **Guion Divino de 12 Palabras**
- Upsell / acelerador: **Acelerador del Guion Divino**
- As "12 palavras" → **12 Palabras** (manter como mecanismo central da promessa)

## PREÇOS (mudar de BRL para USD)
A oferta em espanhol roda em DÓLAR. Onde aparecer preço em Real (R$), troque por dólar:
- Front (produto principal): **US$17**  (preço riscado sugerido: US$47 ou "Normalmente US$97")
- Upsell / acelerador: **US$27**  (recorrência)
- (Outros upsells/downsell, se houver: US$17 a US$37)
- Troque o símbolo `R$` por `US$` e ajuste os valores conforme acima.
- IMPORTANTE: onde houver menção a "Pix", "boleto" ou meios de pagamento brasileiros,
  troque por linguagem neutra ("tarjeta", "pago seguro") — LATAM usa mais cartão.

## LINK DO CHECKOUT (MUITO IMPORTANTE)
O checkout em espanhol NÃO usa a Cakto (que é só Brasil). Vou usar outra plataforma internacional.
- Onde houver o link do checkout da Cakto (algo como `pay.cakto.com.br/96yyuuz_949695`),
  SUBSTITUA por um placeholder: `#LINK-CHECKOUT-ES` (eu troco depois pelo link real da nova plataforma).
- No upsell, faça o mesmo: substitua o componente/one-click da Cakto pelo placeholder `#LINK-UPSELL-ES`.

## CLOAKER — `es/index.php`
Neste arquivo, altere APENAS esta linha:
- De:  `define('ONLY_BRAZIL', true);`
- Para: `define('ONLY_BRAZIL', false);`
(porque agora o público é internacional/LATAM, não só Brasil). NÃO mude mais nada neste arquivo.

## ENTREGA
Ao terminar, me diga:
1. Quais arquivos você traduziu.
2. Onde ficaram os placeholders `#LINK-CHECKOUT-ES` e `#LINK-UPSELL-ES` (linha/arquivo),
   pra eu colocar os links reais.
3. Qualquer trecho que ficou em dúvida na tradução.
