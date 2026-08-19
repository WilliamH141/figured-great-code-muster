<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { money } from '../format';

const invoices = ref([]);
const categories = ref([]);
const selected = ref(null);
const loading = ref(true);
const saving = ref(false);
const savedAt = ref(null);

// The entry form being filled in for the selected invoice.
const form = ref(emptyForm());

// AI-assisted extraction: reads the scanned text into the form above, and
// separately checks whether a client has emailed disputing this supplier.
const extracting = ref(false);
const extractError = ref('');
const dispute = ref(null);

function emptyForm() {
    return {
        supplier: '',
        invoice_date: '',
        total: null,
        category_id: null,
        lines: [{ description: '', amount: null }],
    };
}

onMounted(async () => {
    const { data } = await axios.get('/api/invoices');
    invoices.value = data.invoices;
    categories.value = data.categories;
    loading.value = false;
});

function open(invoice) {
    selected.value = invoice;
    savedAt.value = null;
    extractError.value = '';
    dispute.value = null;
    if (invoice.entered_at) {
        // Pre-fill from the saved entry so it can be reviewed or corrected.
        form.value = {
            supplier: invoice.supplier,
            invoice_date: invoice.invoice_date,
            total: invoice.total,
            category_id: invoice.category_id,
            lines: invoice.lines.map((l) => ({ description: l.description, amount: l.amount })),
        };
    } else {
        form.value = emptyForm();
    }
}

async function extractWithAi() {
    extracting.value = true;
    extractError.value = '';
    try {
        const { data } = await axios.post(`/api/invoices/${selected.value.id}/extract`);
        if (Object.keys(data.fields ?? {}).length) {
            form.value = {
                supplier: data.fields.supplier ?? '',
                invoice_date: data.fields.invoice_date ?? '',
                total: data.fields.total ?? null,
                category_id: data.fields.category_id ?? null,
                lines: data.fields.lines?.length ? data.fields.lines : [{ description: '', amount: null }],
            };
        } else {
            extractError.value = "Couldn't read this scan — key it in by hand.";
        }
        dispute.value = data.dispute ?? null;
    } catch (e) {
        extractError.value = e.response?.data?.error ?? 'Something went wrong reading the scan.';
    } finally {
        extracting.value = false;
    }
}

function addLine() {
    form.value.lines.push({ description: '', amount: null });
}

function removeLine(index) {
    form.value.lines.splice(index, 1);
}

const lineTotal = computed(() => form.value.lines.reduce((sum, l) => sum + (Number(l.amount) || 0), 0));

async function save() {
    saving.value = true;
    const { data } = await axios.put(`/api/invoices/${selected.value.id}`, form.value);
    Object.assign(selected.value, data);
    saving.value = false;
    savedAt.value = new Date();
}
</script>

<template>
    <div>
        <div class="mb-4">
            <h2 class="text-lg font-semibold">Invoice entry</h2>
            <p class="text-sm text-fg-mid-grey">
                Key each supplier invoice into the system from its scanned text. The RD1 invoice is entered already as
                an example. Check the numbers — scans aren't always right.
            </p>
        </div>

        <p v-if="loading" class="text-fg-light-grey">Loading…</p>

        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-4">
            <!-- Invoice list -->
            <div class="overflow-hidden rounded border border-fg-muted-grey bg-white">
                <button
                    v-for="invoice in invoices"
                    :key="invoice.id"
                    class="block w-full border-b border-fg-pale-grey px-3 py-2 text-left text-sm hover:bg-fg-pale-grey"
                    :class="selected?.id === invoice.id ? 'bg-fg-main-blue-9' : ''"
                    @click="open(invoice)"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate font-mono text-xs">{{ invoice.filename }}</span>
                        <span
                            v-if="invoice.entered_at"
                            class="shrink-0 rounded-full bg-fg-positive-15 px-2 py-0.5 text-xs text-fg-positive-dark"
                        >
                            entered
                        </span>
                    </div>
                </button>
            </div>

            <!-- Raw invoice text -->
            <div class="lg:col-span-2">
                <p v-if="!selected" class="rounded border border-dashed border-fg-muted-grey p-8 text-center text-fg-light-grey">
                    Select an invoice.
                </p>
                <template v-else>
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-xs font-medium text-fg-mid-grey">Scanned text</p>
                        <button
                            class="rounded border border-fg-main-blue px-3 py-1 text-xs font-medium text-fg-main-blue hover:bg-fg-main-blue-9 disabled:opacity-50"
                            :disabled="extracting"
                            @click="extractWithAi"
                        >
                            {{ extracting ? 'Reading the scan…' : 'Fill from scan (AI)' }}
                        </button>
                    </div>
                    <pre
                        class="overflow-x-auto rounded border border-fg-muted-grey bg-white p-4 font-mono text-xs leading-relaxed"
                        >{{ selected.raw_text }}</pre
                    >
                    <p v-if="extractError" class="mt-2 text-sm text-fg-danger-dark">{{ extractError }}</p>
                    <div v-if="dispute" class="mt-2 rounded bg-fg-warning-15 p-3 text-sm text-fg-warning-text">
                        <p class="font-medium">A client has emailed about this supplier — check before saving</p>
                        <p class="mt-1">
                            <span class="font-medium">{{ dispute.from_name }}</span> — "{{ dispute.subject }}": {{ dispute.snippet }}
                        </p>
                    </div>
                </template>
            </div>

            <!-- Entry form -->
            <div v-if="selected" class="rounded border border-fg-muted-grey bg-white p-4">
                <h3 class="mb-3 text-sm font-semibold">Entry form</h3>

                <label class="block text-xs font-medium text-fg-mid-grey">Supplier</label>
                <input v-model="form.supplier" class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-sm" />

                <label class="block text-xs font-medium text-fg-mid-grey">Invoice date</label>
                <input
                    v-model="form.invoice_date"
                    type="date"
                    class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-sm"
                />

                <label class="block text-xs font-medium text-fg-mid-grey">Category</label>
                <select v-model="form.category_id" class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-sm">
                    <option :value="null">— choose —</option>
                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                </select>

                <label class="block text-xs font-medium text-fg-mid-grey">Line items (excl. GST)</label>
                <div v-for="(line, index) in form.lines" :key="index" class="mb-1 flex gap-1">
                    <input
                        v-model="line.description"
                        placeholder="Description"
                        class="w-full rounded border border-fg-muted-grey px-2 py-1 text-xs"
                    />
                    <input
                        v-model.number="line.amount"
                        type="number"
                        step="0.01"
                        placeholder="0.00"
                        class="w-24 rounded border border-fg-muted-grey px-2 py-1 text-right font-mono text-xs"
                    />
                    <button
                        v-if="form.lines.length > 1"
                        class="px-1 text-fg-light-grey hover:text-fg-danger"
                        title="Remove line"
                        @click="removeLine(index)"
                    >
                        ✕
                    </button>
                </div>
                <button class="mb-2 text-xs text-fg-main-blue hover:text-fg-main-blue-hover hover:underline" @click="addLine">+ add line</button>
                <p class="mb-2 text-xs text-fg-light-grey">Lines sum to {{ money(lineTotal) }}</p>

                <label class="block text-xs font-medium text-fg-mid-grey">Invoice total (incl. GST)</label>
                <input
                    v-model.number="form.total"
                    type="number"
                    step="0.01"
                    class="mb-3 w-full rounded border border-fg-muted-grey px-2 py-1 text-right font-mono text-sm"
                />

                <button
                    class="w-full rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                    :disabled="saving"
                    @click="save"
                >
                    {{ saving ? 'Saving…' : 'Save entry' }}
                </button>
                <p v-if="savedAt" class="mt-1 text-center text-xs text-fg-positive-dark">Saved ✓</p>
            </div>
        </div>
    </div>
</template>
