#!/usr/bin/env python3
"""Cobertura de un manual técnico: ¿el manual menciona cada pieza del módulo?

Inventaría el código real (backend, rutas del OpenAPI, tablas, frontend, pruebas) y comprueba que el manual
docs/manuales-tecnicos/<modulo>.md nombre cada elemento. Mide presencia, no calidad: la corrección de lo escrito la valida
el Senior Reviewer contrastando una muestra contra el código.

Uso: python3 scripts/manual-coverage.py <modulo> [--frontend ../atlanticerp-frontend] [--openapi ruta] [--json]
Sale con código 1 si falta algún elemento.
"""
import argparse
import json
import re
import sys
from pathlib import Path

BACK = Path(__file__).resolve().parent.parent

# Config por módulo. Añadir una entrada por manual nuevo.
MODULES = {
    'compras': {
        'titulo': 'Compras', 'dir': 'Compras', 'openapi_prefix': 'compras', 'erd': 'compras',
        'fe_dirs': ['src/pages/compras', 'src/components/compras'],
        'fe_extra': ['src/api/comprasApi.ts', 'src/hooks/useCompras.ts', 'src/types/compras.ts', 'src/utils/searchSuggestionsCompras.ts',
                     'src/utils/orderStatusLabel.ts', 'src/components/ComprasSettingsPanel.tsx', 'src/pages/InventarioPage.tsx'],
        'tests_dirs': ['tests/Feature/Compras', 'tests/Unit/Compras'],
        'listeners': ['app/Modules/Bodega/Listeners/ReleaseOrdersAwaitingStock.php',
                      'app/Modules/Servicios/Listeners/SyncInsumoReservationOnPurchaseOrderReceived.php'],
    },
    'bodega': {
        'titulo': 'Bodega', 'dir': 'Bodega', 'openapi_prefix': 'bodega', 'erd': 'bodega',
        'fe_dirs': ['src/components/bodega'],
        'fe_extra': ['src/api/bodegaApi.ts', 'src/hooks/useBodega.ts', 'src/types/bodega.ts', 'src/utils/searchSuggestionsBodega.ts',
                     'src/components/BodegaSettingsPanel.tsx', 'src/pages/Bodega*.tsx', 'src/pages/Bodegas*.tsx'],
        # Archivos de src/ que importan estos módulos (las pantallas del tablero de pedidos no llevan "Bodega" en el nombre).
        'fe_importers': ['@/api/bodegaApi', '@/hooks/useBodega'],
        'tests_dirs': ['tests/Feature/Bodega', 'tests/Unit/Bodega'],
        'listeners': [],
    },
    'factura-electronica': {
        'titulo': 'Factura Electrónica', 'dir': 'AdminCont/FacturacionElectronica', 'openapi_prefix': 'admin-contab',
        # Solo las rutas de FE dentro de admin-contab (más las de validación de RUC en ventas-diseno).
        'openapi_regex': r'/fe-|/fiscal-settings|/invoices/receptors/|/validate-tax-id|/fiscal-directory|/tax-data',
        'openapi_prefixes_extra': ['ventas-diseno'],
        'erd': 'factura-electronica',
        'tables_only': ['fe_transmisiones', 'fiscal_settings', 'company_profile', 'itbms_rates'],
        'extra_back': {
            'Capa de FE (adaptadores, DTO, mapeadores, contratos)': [
                'app/Modules/AdminCont/FacturacionElectronica/Adapters/**/*.php',
                'app/Modules/AdminCont/FacturacionElectronica/DTOs/*.php',
                'app/Modules/AdminCont/FacturacionElectronica/Mappers/*.php',
                'app/Modules/AdminCont/FacturacionElectronica/Contracts/*.php'],
            'Controladores de FE (fuera de la capa)': [
                'app/Modules/AdminCont/Http/Controllers/AdminContFe*.php',
                'app/Modules/AdminCont/Http/Controllers/AdminContFiscalSettingsController.php',
                'app/Modules/AdminCont/Http/Controllers/AdminContInvoiceReceptorController.php',
                'app/Modules/VentasDiseno/Http/Controllers/ClientTaxDataController.php'],
            'Modelos y solicitudes de FE': [
                'app/Modules/AdminCont/Models/AdminContFeTransmision.php',
                'app/Modules/AdminCont/Models/AdminContFiscalSettings.php',
                'app/Modules/AdminCont/Models/AdminContCompanyProfile.php',
                'app/Modules/AdminCont/Http/Requests/UpdateFiscalSettingsRequest.php',
                'app/Modules/AdminCont/Http/Requests/CorrectInvoiceReceptorRequest.php',
                'app/Modules/VentasDiseno/Http/Requests/ValidateTaxIdRequest.php',
                'app/Modules/VentasDiseno/Http/Requests/UpdateSubClientTaxDataRequest.php'],
            'Comandos y seeders de FE': [
                'app/Console/Commands/AdminContSetPacProviderCommand.php',
                'app/Console/Commands/ClientsRevalidateTaxIdsCommand.php',
                'database/seeders/AdminContFiscalIdentitySeeder.php'],
        },
        'fe_dirs': [],
        'fe_extra': ['src/components/admin-contab/FiscalElectronicStatusBadge.tsx', 'src/components/admin-contab/ReceptorGuardNotice.tsx',
                     'src/components/FiscalSettingsPanel.tsx', 'src/pages/admin-contab/FiscalConfigPage.tsx',
                     'src/pages/admin-contab/ClientesFiscalPage.tsx', 'src/components/TaxIdValidator.tsx',
                     'src/components/EditSubClientTaxDataModal.tsx', 'src/components/admin-contab/InvoiceDetailModal.tsx',
                     'src/components/admin-contab/DetalleNotaCreditoModal.tsx', 'src/api/adminContabApi.ts',
                     'src/api/ventasDisenoApi.ts', 'src/hooks/useAdminContab.ts', 'src/types/adminContab.ts'],
        'tests_dirs': ['tests/Feature/AdminCont/FacturacionElectronica', 'tests/Unit/AdminCont/FacturacionElectronica'],
        'tests_globs': ['tests/Feature/AdminCont/AdminContFiscalSettingsControllerTest.php',
                        'tests/Feature/AdminCont/AdminContFiscalIdentitySeederTest.php',
                        'tests/Feature/AdminCont/AdminContSetPacProviderCommandTest.php',
                        'tests/Feature/AdminCont/InvoiceReceptorGuardTest.php',
                        'tests/Feature/Console/ClientsRevalidateTaxIdsCommandTest.php',
                        'tests/Feature/VentasDiseno/ClientTaxDataTest.php',
                        'tests/Unit/AdminCont/ItbmsCodeMapperTest.php', 'tests/Unit/AdminCont/MslJsonMapperTest.php',
                        'tests/Unit/AdminCont/MslPacAdapterTest.php', 'tests/Unit/AdminCont/MslPaymentMethodMapperTest.php'],
        'listeners': [],
    },
    'crm': {
        'titulo': 'CRM', 'dir': 'CRM', 'openapi_prefix': 'crm',
        # Además de /crm/*: reglas de notificación y exportación (admin) y el enlace público de documentos compartidos.
        'openapi_prefixes_extra': ['admin', 'public'],
        'openapi_regex': r'^/(crm/|admin/notification-rules|admin/export/crm|public/share)',
        'erd': 'crm',
        'tables_only': ['contacts', 'crm_settings', 'notification_rules', 'project_activities', 'project_assignees',
                        'project_documents', 'project_reminders', 'projects', 'share_links'],
        'extra_back': {
            'Motor de reglas de notificación (Notifications, Observers)': [
                'app/Modules/CRM/Notifications/*.php', 'app/Modules/CRM/Observers/*.php'],
        },
        'fe_dirs': [],
        'fe_extra': ['src/pages/crm/DashboardPage.tsx', 'src/pages/crm/ProjectsListPage.tsx', 'src/pages/security/NotificationRulesPage.tsx',
                     'src/components/CrmSettingsPanel.tsx', 'src/components/ProjectModal.tsx', 'src/components/DocumentSection.tsx',
                     'src/components/ShareLinkModal.tsx', 'src/components/ActivityTimeline.tsx', 'src/components/AlertBanner.tsx',
                     'src/components/KpiGrid.tsx', 'src/components/KanbanBoard.tsx', 'src/components/ProjectCard.tsx',
                     'src/components/ContactoAutocomplete.tsx', 'src/api/projectsApi.ts', 'src/api/contactsApi.ts',
                     'src/api/directoryApi.ts', 'src/api/autocompleteApi.ts', 'src/api/reportsApi.ts',
                     'src/api/notificationRulesApi.ts', 'src/types/project.ts', 'src/types/reports.ts', 'src/types/directory.ts',
                     'src/utils/urgencyUtils.ts'],
        'tests_dirs': ['tests/Feature/CRM'],
        'listeners': [],
    },
    'ventas-diseno': {
        'titulo': 'Ventas & Diseño', 'dir': 'VentasDiseno', 'openapi_prefix': 'ventas-diseno', 'erd': 'ventas-diseno',
        'extra_back': {'Observers': ['app/Modules/VentasDiseno/Observers/*.php']},
        'fe_dirs': ['src/pages/ventas-diseno'],
        'fe_extra': ['src/api/ventasDisenoApi.ts', 'src/types/ventasDiseno.ts', 'src/components/quote/QuotePartsApiContext.ts',
                     'src/components/ArchitectDetailModal.tsx', 'src/components/CalendarModal.tsx', 'src/components/ClientDetailModal.tsx',
                     'src/components/ClientPicker.tsx', 'src/components/ClientProjectsModal.tsx', 'src/components/CommissionsPanel.tsx',
                     'src/components/ConditionsSettingsPanel.tsx', 'src/components/CreateClientModal.tsx',
                     'src/components/HomeItemDetailModal.tsx', 'src/components/MarginOverrideWarningModal.tsx',
                     'src/components/MiniCalendarCard.tsx', 'src/components/NewProjectModal.tsx', 'src/components/OrdersSettingsPanel.tsx',
                     'src/components/PedidoDetailModal.tsx', 'src/components/PipelineCardModal.tsx', 'src/components/PipelineFileViewerModal.tsx',
                     'src/components/PricingSettingsPanel.tsx', 'src/components/ProductDeliveryAssignmentModal.tsx',
                     'src/components/QuotePartCard.tsx', 'src/components/QuoteViewerModal.tsx', 'src/components/ReceptorTipoSelect.tsx',
                     'src/components/ReportsConfigPanel.tsx', 'src/components/SaveQuoteConfirmModal.tsx',
                     'src/components/SubClientIcgHistoryModal.tsx', 'src/components/SalesCommissionTiersPanel.tsx'],
        'tests_dirs': ['tests/Feature/VentasDiseno', 'tests/Unit/VentasDiseno'],
        'listeners': [],
    },
    'servicios': {
        'titulo': 'Servicios', 'dir': 'Servicios', 'openapi_prefix': 'servicios', 'erd': 'servicios',
        'fe_dirs': ['src/pages/servicios', 'src/components/servicios'],
        'fe_extra': ['src/api/serviciosApi.ts', 'src/types/servicios.ts', 'src/components/ServiciosSettingsPanel.tsx',
                     'src/components/TechnicalSpecModal.tsx'],
        'tests_dirs': ['tests/Feature/Servicios', 'tests/Unit/Servicios'],
        'listeners': [],
    },
    'admin-contabilidad': {
        'titulo': 'Admin & Contabilidad', 'dir': 'AdminCont', 'openapi_prefix': 'admin-contab', 'erd': 'admin-contabilidad',
        'fe_dirs': ['src/pages/admin-contab', 'src/components/admin-contab'],
        'fe_extra': ['src/api/adminContabApi.ts', 'src/hooks/useAdminContab.ts', 'src/types/adminContab.ts',
                     'src/components/FiscalSettingsPanel.tsx', 'src/components/InvoiceStatusModal.tsx'],
        'tests_dirs': ['tests/Feature/AdminCont', 'tests/Unit/AdminCont'],
        'listeners': [],
    },
    'soporte': {
        'titulo': 'Soporte', 'dir': 'Soporte', 'openapi_prefix': 'soporte', 'erd': None,
        'fe_dirs': [],
        'fe_extra': ['src/pages/SoportePage.tsx', 'src/api/soporteApi.ts', 'src/types/soporte.ts'],
        'tests_dirs': ['tests/Feature/Soporte'],
        'listeners': [],
    },
    'gerencia': {
        'titulo': 'Gerencia', 'dir': 'Gerencia', 'openapi_prefix': 'gerencia', 'erd': 'gerencia',
        'fe_dirs': ['src/components/gerencia'],
        'fe_extra': ['src/pages/GerenciaPage.tsx', 'src/api/gerenciaApi.ts', 'src/hooks/useGerencia.ts', 'src/types/gerencia.ts'],
        'tests_dirs': ['tests/Feature/Gerencia'],
        'listeners': [],
    },
}


def php_classes(base, sub):
    return sorted(p.stem for p in (base / sub).rglob('*.php')) if (base / sub).exists() else []


def erd_tables(erd):
    f = BACK.parent / 'docs' / 'architecture' / 'erd' / f'{erd}.md'
    if not f.exists():
        return []
    # tablas propias = bloques "nombre {" sin el prefijo de otro schema (doble guion bajo)
    return sorted(t for t in re.findall(r'^    ([a-z0-9_]+) \{', f.read_text(), re.M) if '__' not in t)


def fe_files(fe_root, cfg):
    out = []
    for d in cfg['fe_dirs']:
        for p in sorted((fe_root / d).rglob('*.ts*')) if (fe_root / d).exists() else []:
            if '.test.' not in p.name and '.spec.' not in p.name:
                out.append(p.stem)
    for g in cfg['fe_extra']:
        out += [p.stem for p in sorted(fe_root.glob(g)) if '.test.' not in p.name]
    needles = cfg.get('fe_importers', [])
    if needles:
        for p in sorted((fe_root / 'src').rglob('*.ts*')):
            if '.test.' in p.name or '.spec.' in p.name:
                continue
            txt = p.read_text(errors='ignore')
            if any(n in txt for n in needles):
                out.append(p.stem)
    return sorted(set(out))


def inventory(cfg, fe_root, openapi):
    base = BACK / 'app' / 'Modules' / cfg['dir']
    inv = {}
    for label, sub in [('Controladores', 'Http/Controllers'), ('Form Requests', 'Http/Requests'), ('Modelos', 'Models'),
                       ('Servicios', 'Services'), ('Jobs', 'Jobs'), ('Eventos', 'Events'), ('Correos', 'Mail'), ('Soporte', 'Support'),
                       ('Excepciones', 'Exceptions'), ('Listeners propios', 'Listeners')]:
        inv[label] = php_classes(base, sub)
    inv['Listeners (de otros módulos)'] = sorted(Path(p).stem for p in cfg['listeners'] if (BACK / p).exists())
    tablas = erd_tables(cfg['erd'])
    if cfg.get('tables_only'):
        tablas = [t for t in tablas if t in cfg['tables_only']]
    inv['Tablas'] = tablas
    for label, globs in cfg.get('extra_back', {}).items():
        found = []
        for g in globs:
            found += [p.stem for p in sorted(BACK.glob(g))]
        inv[label] = sorted(set(found))
    paths = set()
    if openapi and Path(openapi).exists():
        spec = json.loads(Path(openapi).read_text())
        prefixes = [cfg['openapi_prefix']] + cfg.get('openapi_prefixes_extra', [])
        rx = re.compile(cfg['openapi_regex']) if cfg.get('openapi_regex') else None
        for p in spec['paths']:
            if p.strip('/').split('/')[0] in prefixes and (rx is None or rx.search(p)):
                paths.add(p)
    inv['Rutas API'] = sorted(paths)
    inv['Frontend (páginas, componentes, hooks, tipos)'] = fe_files(fe_root, cfg)
    tests = []
    for d in cfg['tests_dirs']:
        tests += [p.stem for p in sorted((BACK / d).rglob('*Test.php'))] if (BACK / d).exists() else []
    for g in cfg.get('tests_globs', []):
        tests += [p.stem for p in sorted(BACK.glob(g))]
    inv['Pruebas backend'] = sorted(set(tests))
    return inv


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('modulo')
    ap.add_argument('--frontend', default=str(BACK.parent / 'atlanticerp-frontend'))
    ap.add_argument('--openapi')
    ap.add_argument('--json', action='store_true')
    a = ap.parse_args()
    cfg = MODULES[a.modulo]
    openapi = a.openapi or (sorted((BACK.parent / 'docs' / 'api').glob('openapi-*.json')) or [None])[-1]
    manual = BACK / 'docs' / 'manuales-tecnicos' / f'{a.modulo}.md'
    text = manual.read_text() if manual.exists() else ''
    inv = inventory(cfg, Path(a.frontend), openapi)
    total = found = 0
    report, missing = {}, {}
    for cat, items in inv.items():
        miss = [i for i in items if i not in text]
        missing[cat] = miss
        report[cat] = {'total': len(items), 'cubiertos': len(items) - len(miss)}
        total += len(items)
        found += len(items) - len(miss)
    if a.json:
        print(json.dumps({'modulo': a.modulo, 'cubiertos': found, 'total': total, 'categorias': report, 'faltan': missing}, ensure_ascii=False, indent=1))
    else:
        print(f"Manual técnico de {cfg['titulo']}: {found}/{total} elementos mencionados ({100 * found / total:.1f} %)" if total else 'Inventario vacío')
        if not manual.exists():
            print(f'  (no existe {manual.relative_to(BACK)})')
        for cat, r in report.items():
            print(f"  {cat}: {r['cubiertos']}/{r['total']}")
        for cat, miss in missing.items():
            if miss:
                print(f"\nFaltan en {cat}:")
                for i in miss:
                    print(f'  - {i}')
    sys.exit(0 if found == total else 1)


if __name__ == '__main__':
    main()
