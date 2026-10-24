# Pre-QA en dev — `legacy:vincular-productos-proveedores` (2026-10-14/15)

**Entorno:** dev.atlanticerp.ai, backend `5aa00015` (gate verde: PHPStan 8, 5.573 pruebas, 0 fallos). **Veredicto: PASA** (datos). Verificación en pantalla pendiente.

| Criterio | Resultado |
|---|---|
| Dry-run sin escribir y con huella estable | PASA: 9.353 a vincular (9.105 un proveedor + 248 varios), huella `b1a2e4826cbe4ebf76a381749ff96d41`; coincide con lo medido en prod (9.091 + 249) |
| Aplicar (huella correcta, `--operador`) | PASA: 9.353 productos, evento de auditoría 291209 |
| 2.ª corrida | PASA: "Nada que aplicar" |
| El principal siempre está en la pivote | PASA: 0 productos aplicados con principal fuera de la pivote |
| Empate / sin compras | PASA: 1 caso (id 4744, artículo 60505, proveedores ICG 7 y 1030): principal el de menor código (122 = ICG 7), adicional 181 |
| Varios proveedores | PASA: 231 con 2, 13 con 3, 3 con 4 y 3 productos de un mismo proveedor principal con 8, 20 y 29 proveedores |
| Proveedor sin enlace omitido | PASA: 2 productos con un proveedor omitido, el resto vinculado |
| Choque de `factory_reference` | PASA: 0 (20 productos vinculados con referencia, ninguno choca) |
| Reversa | PASA: `--revert=291209` revirtió 9.353 de 9.353 y el dry-run volvió a dar la misma huella |
| Reaplicar | PASA: evento 291211, 9.353 productos, 2.ª corrida "Nada que aplicar" |
| Costo y proveedor coherentes | PASA: A691-451471527K con costo 480,06 y principal MARSET ILUMINACION, S.A. |

## Pendiente
- Verificar en Inventario (pantalla) que un producto con varios proveedores los muestra todos con el principal primero (p. ej. el 4744 o el 1958): no se hizo por falta de credenciales de dev para Playwright.
- Los productos 1958, 1963 y 1964 (proveedor principal 140, con 29, 8 y 20 proveedores) conviene revisarlos con Compras: una lista tan larga puede ser un artículo genérico.
- 2.340 productos sin costo en ICG siguen sin proveedor (sin dato de dónde deducirlo).
