# Reporte de Seguridad — Orangecat_Prices

**Fecha:** 2026-06-05  
**Módulo:** `Orangecat_Prices`  
**Alcance:** Revisión manual contra políticas de seguridad de Magento 2  
**Todos los hallazgos fueron verificados leyendo el código fuente**

---

## Resumen ejecutivo

| # | Severidad | Archivo | Líneas | Problema |
|---|-----------|---------|--------|----------|
| 1 | MEDIUM-HIGH | `view/frontend/web/js/b2b-prices-core.js` | 113, 131, 145 | `.html()` con datos del servidor — debería ser `.text()` |
| 2 | MEDIUM-HIGH | `b2b-prices-core.js:189`, `b2b-tier-prices_hyva.phtml:43-46`, `breeze/prices-mixins.js:48-51` | múltiples | Construcción de HTML via concatenación sin escapar |
| 3 | MEDIUM | `Controller/Ajax/AjaxPrices.php` | 88 | Sin límite de tamaño en el body del request |
| 4 | MEDIUM | `Controller/Ajax/AjaxPrices.php` | 118, 128, 138 | `productRepository` sin store scope ni status check |
| 5 | MEDIUM | `view/frontend/web/js/breeze/prices-mixins.js` | 5, 17, 34 | `console.log` expone SKUs y precios en producción |
| 6 | LOW | `Controller/Ajax/AjaxPrices.php` | 360-363 | CSRF bypass sin validación de Origin |
| 7 | LOW | `Plugin/App/HttpContextPlugin.php` | 107-150 | Método muerto con lógica de sesión |
| 8 | LOW | `view/frontend/web/js/breeze/prices-mixins.js` | 107 | Selector CSS con interpolación sin sanitizar |

---

## Hallazgos detallados

---

### #1 — MEDIUM-HIGH: XSS via jQuery `.html()` con datos del servidor

**Archivo:** `view/frontend/web/js/b2b-prices-core.js`  
**Líneas:** 113, 131, 145

**Código problemático:**
```js
// línea 113
$(this).find('.price').html(p.formatted);

// línea 131
$(this).find('.price').html(p.min_price_formatted);

// línea 145
$(this).find('.price').html(p.max_price_formatted);
```

**Descripción:**  
`p.formatted`, `p.min_price_formatted` y `p.max_price_formatted` provienen de la respuesta JSON del endpoint `/prices/ajax/ajaxprices`. Aunque estos valores son generados por `priceCurrency->format(float)` en el servidor (y en condiciones normales producen strings como `"$12.50"`), usar `.html()` en lugar de `.text()` deja la puerta abierta a XSS si:
- Una calculadora de precios upstream devuelve datos con contenido HTML
- La base de datos de precios es comprometida y un campo de precio contiene markup
- Un proxy o cache intermedio modifica la respuesta AJAX

Magento 2 establece como principio de defense-in-depth que todo dato externo al código fuente debe tratarse como untrusted al insertarse en el DOM.

**Fix recomendado:**
```js
// Reemplazar .html() por .text() en las tres líneas
$(this).find('.price').text(p.formatted);
$(this).find('.price').text(p.min_price_formatted);
$(this).find('.price').text(p.max_price_formatted);
```

---

### #2 — MEDIUM-HIGH: XSS via concatenación directa de datos en strings HTML

**Archivos y líneas:**
- `view/frontend/web/js/b2b-prices-core.js:189`
- `view/frontend/templates/b2b-tier-prices_hyva.phtml:43-46`
- `view/frontend/web/js/breeze/prices-mixins.js:48-51`

**Código problemático:**
```js
// b2b-prices-core.js:189
html += '<li class="item" style="margin-bottom: 5px;">Buy ' + tier.qty +
    ' for <span class="price-container price-tier_price"><span class="price-wrapper">' +
    '<span class="price" style="font-weight: bold;">' + tier.formatted +
    '</span></span></span> each</li>';

// b2b-tier-prices_hyva.phtml:43-46 (mismo patrón)
html += '<li class="item" style="margin-bottom:5px;">'
    + 'Buy ' + tier.qty + ' for '
    + '<span class="price" style="font-weight:bold;">' + tier.formatted + '</span>'
    + '</li>';

// breeze/prices-mixins.js:48-51 (mismo patrón)
html += '<li class="item" style="margin-bottom:5px;">Buy ' + tier.qty +
    ' for ...<span class="price">' + tier.formatted + '</span>...</li>';
```

**Descripción:**  
Los valores `tier.qty` (entero) y `tier.formatted` (string de moneda) son insertados sin escapar en strings HTML que luego se asignan a `innerHTML` / `$element.after(html)`. 

`tier.formatted` viene de `priceCurrency->format($tier['price'])` — actualmente seguro.  
`tier.qty` viene de la BD de listas de precios — si un admin puede escribir texto arbitrario en el campo `qty`, es un vector de XSS almacenado.

**Fix recomendado:**

Agregar una función helper de escape al inicio del script y aplicarla:
```js
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// Uso:
html += '<li>Buy ' + escHtml(tier.qty) + ' for <span>' + escHtml(tier.formatted) + '</span></li>';
```

Alternativamente, construir los elementos con `document.createElement` y asignar via `textContent`:
```js
var li = document.createElement('li');
var span = document.createElement('span');
span.className = 'price';
span.textContent = tier.formatted;
li.textContent = 'Buy ' + tier.qty + ' for ';
li.appendChild(span);
ul.appendChild(li);
```

---

### #3 — MEDIUM: Sin límite de tamaño en el body del request

**Archivo:** `Controller/Ajax/AjaxPrices.php`  
**Línea:** 88

**Código problemático:**
```php
$body = $this->request->getContent();
$data = json_decode($body, true);  // sin verificar strlen($body) primero
```

**Descripción:**  
`MAX_SKUS = 200` limita la cantidad de SKUs en el array, pero no hay control sobre el tamaño total del body antes de llamar a `json_decode()`. Un atacante podría enviar un body de varios MB (ej: un SKU de 10MB de longitud) causando consumo de memoria proporcional al tamaño del payload en cada request. Esto es un vector de DoS por agotamiento de memoria en el proceso PHP.

**Fix recomendado:**
```php
$body = $this->request->getContent();

// Rechazar payloads mayores a 64KB (muy por encima de lo necesario para 200 SKUs)
if (strlen($body) > 65536) {
    return $result->setData(['prices' => new \stdClass()]);
}

$data = json_decode($body, true);
```

---

### #4 — MEDIUM: `productRepository` sin store scope ni validación de status

**Archivo:** `Controller/Ajax/AjaxPrices.php`  
**Líneas:** 118, 128, 138

**Código problemático:**
```php
// línea 118
$product = $this->productRepository->get($sku);

// línea 128
$product = $this->productRepository->getById($pId);

// línea 138
$product = $this->productRepository->getById($pId);
```

**Descripción:**  
`productRepository->get($sku)` sin pasar `$storeId` usa el store admin (ID 0) como contexto por defecto. Esto significa que productos deshabilitados en el storefront actual, o productos no asignados al store del usuario, pueden ser consultados a través de este endpoint. Un usuario podría obtener el precio de un producto que no debería poder ver.

**Fix recomendado:**

Inyectar `\Magento\Store\Model\StoreManagerInterface` y usar el store scope correcto:
```php
use Magento\Catalog\Model\Product\Attribute\Source\Status;

$storeId = $this->storeManager->getStore()->getId();
$product = $this->productRepository->get($sku, false, $storeId);

// Saltar productos deshabilitados
if ((int)$product->getStatus() !== Status::STATUS_ENABLED) {
    continue;
}
```

---

### #5 — MEDIUM: `console.log` en código de producción expone datos sensibles

**Archivo:** `view/frontend/web/js/breeze/prices-mixins.js`  
**Líneas:** 5, 17, 34, 57, 60, 65

**Código problemático:**
```js
console.log('B2B Prices Mixins Loaded (Breeze)');             // línea 5
console.log('B2B Inventory AJAX for SKU:', productSku);       // línea 17 — expone SKU exacto
console.log('B2B Inventory Response:', response);             // línea 34 — expone estructura completa de precios tier
console.log('B2B tiers rendered after priceBox');             // línea 57
console.log('B2B tiers rendered in product-info-main ...');   // línea 60
console.log('B2B Inventory AJAX Failed');                     // línea 65
```

**Descripción:**  
Cualquier usuario con acceso a DevTools del browser (F12) puede ver:
- Los SKUs exactos de todos los productos simples que se consultan (línea 17)
- La estructura completa de la respuesta con todos los tier prices y quantities de la empresa B2B (línea 34)

Esto es information disclosure. En un contexto B2B donde los precios son confidenciales y estratégicos, exponer tier pricing en la consola del browser es un riesgo real.

**Fix recomendado:**

Eliminar todos los `console.log` o reemplazarlos con un flag de debug:
```js
var DEBUG = window.b2bPricesDebug === true;

// Reemplazar:
console.log('B2B Inventory AJAX for SKU:', productSku);
// Por:
if (DEBUG) console.log('B2B Inventory AJAX for SKU:', productSku);
```

---

### #6 — LOW: CSRF bypass sin validación de Origin

**Archivo:** `Controller/Ajax/AjaxPrices.php`  
**Líneas:** 360-363

**Código problemático:**
```php
public function validateForCsrf(RequestInterface $request): ?bool
{
    // This is a JSON API endpoint — CSRF is not applicable
    return true;
}
```

**Descripción:**  
El controller implementa `CsrfAwareActionInterface` y retorna `true` en `validateForCsrf()`, desactivando explícitamente la validación de CSRF de Magento. Para un endpoint JSON POST (no un form submission), esto es aceptable en Magento 2 ya que el CSRF form-key está diseñado para formularios HTML. Además, con cookies `SameSite=Lax` (comportamiento default en browsers modernos), las requests cross-origin no envían cookies, mitigando el riesgo de CSRF.

Sin embargo, no hay ninguna validación de `Origin` o `Referer` header, lo que podría permitir requests desde dominios no esperados en configuraciones de cookies más permisivas (`SameSite=None`).

**Fix recomendado (defense-in-depth):**

Añadir validación de `Origin` como capa adicional:
```php
public function validateForCsrf(RequestInterface $request): ?bool
{
    $origin = $request->getHeader('Origin');
    if ($origin) {
        $storeBaseUrl = $this->storeManager->getStore()->getBaseUrl();
        $allowedHost = parse_url($storeBaseUrl, PHP_URL_HOST);
        $requestHost = parse_url($origin, PHP_URL_HOST);
        if ($requestHost !== $allowedHost) {
            return false;
        }
    }
    return true;
}
```

---

### #7 — LOW: Método muerto con lógica de sesión en Plugin

**Archivo:** `Plugin/App/HttpContextPlugin.php`  
**Líneas:** 107-150

**Código problemático:**
```php
public function OldFPCbeforeGetVaryString(Context $subject): void
{
    // ... 40 líneas de lógica de sesión y manejo de company ID
}
```

**Descripción:**  
El sistema de plugins de Magento 2 solo reconoce métodos cuyo nombre comienza con `before`, `after` o `around`. Un método llamado `OldFPCbeforeGetVaryString` **nunca es invocado por Magento** — es código muerto. Sin embargo:
1. Contiene lógica de manejo de sesión y company ID que podría confundir a futuros desarrolladores
2. Si alguien renombra el método a `beforeGetVaryString` sin entender el contexto, podría reactivar comportamiento duplicado con el método ya activo `beforeGetVaryString` real
3. Aumenta el área de superficie del código a mantener

**Fix recomendado:**  
Eliminar el método completo (líneas 107-150).

---

### #8 — LOW: Interpolación de product ID en selector CSS sin sanitizar

**Archivo:** `view/frontend/web/js/breeze/prices-mixins.js`  
**Línea:** 107

**Código problemático:**
```js
$('[data-role=priceBox][data-product-id=' + confProductId + ']');
```

**Descripción:**  
`confProductId` proviene de `self.options.spConfig.productId` o `self.options.jsonConfig.productId`, que son datos server-side (IDs numéricos de producto). El riesgo real es mínimo ya que Magento controla estos valores. Sin embargo, la interpolación directa en selectores CSS viola las buenas prácticas de Magento y podría causar comportamiento inesperado si el valor contiene caracteres especiales de CSS.

**Fix recomendado:**
```js
// Asegurar que es un entero y usar comillas en el atributo
$('[data-role="priceBox"][data-product-id="' + parseInt(confProductId, 10) + '"]');
```

---

## Notas sobre hallazgos descartados

Los siguientes puntos fueron evaluados y **no** representan vulnerabilidades reales en este contexto:

- **Company ID en HTTP Context** (`HttpContextPlugin.php:100-103`): El valor `orangecat_company_id` se almacena en el HTTP Context para segmentación de FPC/Varnish. Este es un patrón estándar de Magento 2 para cache por cliente. El contexto HTTP no se expone en cookies ni headers de response — se usa internamente para calcular el Vary string del cache. No es information disclosure.

- **Tipo mixto en comparación de product IDs** (`AjaxPrices.php:152`): `in_array((string)$productId, ...) || in_array((int)$productId, ...)` — es redundante pero no inseguro. Los valores fueron previamente filtrados con `is_numeric`.

- **Manejo de excepciones genérico** (`GetQty.php:88`): `catch (\Exception $e)` es usado correctamente para ignorar errores no críticos de tier prices en un endpoint que primero tiene que retornar stock quantity. No hay information disclosure en la respuesta.
