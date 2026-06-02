// Klient-side render varsymbol templatu — zrcadlí PHP VarsymbolGenerator::render().
// Používá se pro live preview v Settings; v editoru faktury preferujeme volání
// /api/invoices/preview-varsymbol (zná aktuální hodnotu counteru z DB).
//
// Placeholdery: {YYYY}, {YY}, {MM}, {C+} (variabilní padding 1..6 znaků),
// {PP} = daňový prefix přijaté faktury (jen náhled — dosadí se vzorek "PF").

export function renderVarsymbolTemplate(
  template: string | null | undefined,
  date: Date,
  counter: number,
): string {
  if (!template) return ''
  const Y = String(date.getFullYear())
  const M = String(date.getMonth() + 1).padStart(2, '0')
  let out = template
    .replaceAll('{PP}', 'PF') // náhledový vzorek; reálný prefix dle daňového typu dokladu
    .replaceAll('{YYYY}', Y)
    .replaceAll('{YY}', Y.slice(-2))
    .replaceAll('{MM}', M)
  out = out.replace(/\{(C+)\}/g, (_match, cs: string) => {
    return String(counter).padStart(cs.length, '0')
  })
  return out
}

/**
 * True pokud template obsahuje counter placeholder {C+}. Bez něj generator selže
 * (resp. dva inserty se stejným varsymbolem narazí na unique constraint).
 */
export function hasCounterPlaceholder(template: string | null | undefined): boolean {
  if (!template) return false
  return /\{C+\}/.test(template)
}
