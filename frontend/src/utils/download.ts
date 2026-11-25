import { api, saveBlob } from '@/api'
import { notifyError } from '@/stores/toast'

export async function download(path: string, fallbackName: string): Promise<void> {
  try {
    const { blob, filename } = await api.download(path)
    saveBlob(blob, filename ?? fallbackName)
  } catch (e) {
    notifyError(e)
  }
}
