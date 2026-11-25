const W1 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]
const W2 = [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2]

export function iinChecksum(first11: string): number | null {
  if (!/^\d{11}$/.test(first11)) return null
  const d = first11.split('').map(Number)
  let c = d.reduce((s, v, i) => s + v * W1[i], 0) % 11
  if (c === 10) {
    c = d.reduce((s, v, i) => s + v * W2[i], 0) % 11
    if (c === 10) return null
  }
  return c
}

export function iinBirthDate(iin: string): string | null {
  if (!/^\d{12}$/.test(iin)) return null
  const centuryDigit = Number(iin[6])
  const century = centuryDigit <= 2 ? 1800 : centuryDigit <= 4 ? 1900 : centuryDigit <= 6 ? 2000 : null
  if (!century || centuryDigit === 0) return null
  const y = century + Number(iin.slice(0, 2))
  const m = Number(iin.slice(2, 4))
  const day = Number(iin.slice(4, 6))
  const date = new Date(Date.UTC(y, m - 1, day))
  if (date.getUTCFullYear() !== y || date.getUTCMonth() !== m - 1 || date.getUTCDate() !== day) return null
  return `${y}-${String(m).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

export function validIin(iin: string): boolean {
  if (!/^\d{12}$/.test(iin)) return false
  const c = iinChecksum(iin.slice(0, 11))
  return c !== null && c === Number(iin[11]) && iinBirthDate(iin) !== null
}
