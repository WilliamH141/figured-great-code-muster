<script setup>
import axios from 'axios';
import { onMounted, ref } from 'vue';

const emails = ref([]);
const selected = ref(null);
const draft = ref('');
const sending = ref(false);
const loading = ref(true);

// AI-assisted drafting. drafting() is the request in flight; agentResult()
// holds what came back - either a drafted reply (which also fills the
// textarea above) or a flag explaining why a human needs to handle it.
const drafting = ref(false);
const agentResult = ref(null);
const agentError = ref('');

// Acting on the action item found in a flagged email (right now: booking a
// disputed invoice). acting() is the request in flight; actResult() is the
// confirmation once it's actually been done.
const acting = ref(false);
const actResult = ref(null);
const actError = ref('');

onMounted(async () => {
    const { data } = await axios.get('/api/emails');
    emails.value = data;
    loading.value = false;
});

function open(email) {
    selected.value = email;
    draft.value = '';
    agentResult.value = null;
    agentError.value = '';
    actResult.value = null;
    actError.value = '';
}

async function draftWithAi() {
    drafting.value = true;
    agentResult.value = null;
    agentError.value = '';
    actResult.value = null;
    actError.value = '';
    try {
        const { data } = await axios.post(`/api/emails/${selected.value.id}/draft`);
        agentResult.value = data;
        if (data.action === 'draft') {
            draft.value = data.reply;
        }
    } catch (e) {
        agentError.value = e.response?.data?.error ?? 'Something went wrong asking the assistant.';
    } finally {
        drafting.value = false;
    }
}

async function doActionItem() {
    acting.value = true;
    actError.value = '';
    try {
        const { data } = await axios.post(`/api/emails/${selected.value.id}/act`);
        actResult.value = data;
    } catch (e) {
        actError.value = e.response?.data?.error ?? 'Something went wrong doing that.';
    } finally {
        acting.value = false;
    }
}

async function sendReply() {
    if (!draft.value.trim()) return;
    sending.value = true;
    const { data } = await axios.post(`/api/emails/${selected.value.id}/reply`, {
        body: draft.value,
    });
    // Update the list with the saved reply.
    Object.assign(selected.value, data);
    draft.value = '';
    sending.value = false;
}

function formatDateTime(iso) {
    return new Date(iso).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <div>
        <div class="mb-4">
            <h2 class="text-lg font-semibold">Inbox</h2>
            <p class="text-sm text-fg-mid-grey">
                Client emails waiting on a reply. Nothing actually sends — replies just get saved. One is already
                answered as an example.
            </p>
        </div>

        <p v-if="loading" class="text-fg-light-grey">Loading…</p>

        <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <!-- Email list -->
            <div class="overflow-hidden rounded border border-fg-muted-grey bg-white">
                <button
                    v-for="email in emails"
                    :key="email.id"
                    class="block w-full border-b border-fg-pale-grey px-3 py-2 text-left hover:bg-fg-pale-grey"
                    :class="selected?.id === email.id ? 'bg-fg-main-blue-9' : ''"
                    @click="open(email)"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">{{ email.from_name }}</span>
                        <span
                            v-if="email.replied_at"
                            class="rounded-full bg-fg-positive-15 px-2 py-0.5 text-xs text-fg-positive-dark"
                        >
                            replied
                        </span>
                    </div>
                    <p class="truncate text-sm text-fg-dark-grey">{{ email.subject }}</p>
                    <p class="text-xs text-fg-light-grey">{{ formatDateTime(email.received_at) }}</p>
                </button>
            </div>

            <!-- Reading pane -->
            <div class="md:col-span-2">
                <p v-if="!selected" class="rounded border border-dashed border-fg-muted-grey p-8 text-center text-fg-light-grey">
                    Select an email to read it.
                </p>

                <div v-else class="space-y-4">
                    <div class="rounded border border-fg-muted-grey bg-white p-4">
                        <p class="text-sm text-fg-light-grey">
                            From <span class="font-medium text-fg-dark-grey">{{ selected.from_name }}</span>
                            &lt;{{ selected.from_email }}&gt; — {{ formatDateTime(selected.received_at) }}
                        </p>
                        <h3 class="mt-1 font-semibold">{{ selected.subject }}</h3>
                        <p class="mt-3 whitespace-pre-wrap text-sm leading-relaxed">{{ selected.body }}</p>
                    </div>

                    <div v-if="selected.reply_body" class="rounded border border-fg-main-blue-30 bg-fg-main-blue-9 p-4">
                        <p class="text-sm font-medium text-fg-dark-blue">
                            Your reply — sent {{ formatDateTime(selected.replied_at) }}
                        </p>
                        <p class="mt-2 whitespace-pre-wrap text-sm leading-relaxed">{{ selected.reply_body }}</p>
                    </div>

                    <!-- AI-assisted draft: pulls real numbers for simple lookups, or
                         flags anything that needs a human (advice, disputes, wellbeing,
                         unrecognised senders). Never sends - only ever fills the box below. -->
                    <div class="rounded border border-dashed border-fg-muted-grey bg-white p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium">Assistant</p>
                            <button
                                class="rounded border border-fg-main-blue px-3 py-1 text-xs font-medium text-fg-main-blue hover:bg-fg-main-blue-9 disabled:opacity-50"
                                :disabled="drafting"
                                @click="draftWithAi"
                            >
                                {{ drafting ? 'Reading the email…' : 'Draft with AI' }}
                            </button>
                        </div>

                        <p v-if="agentError" class="mt-2 text-sm text-fg-danger-dark">{{ agentError }}</p>

                        <div v-else-if="agentResult?.action === 'flag'" class="mt-2 rounded bg-fg-warning-15 p-3 text-sm text-fg-warning-text">
                            <p class="font-medium">Needs a human — not drafted</p>
                            <p class="mt-1">{{ agentResult.reason }}</p>

                            <div v-if="agentResult.action_item" class="mt-3 rounded border border-fg-warning-text/40 bg-white p-3">
                                <p class="font-medium text-fg-dark-grey">Action item found: {{ agentResult.action_item.label }}</p>

                                <p v-if="actError" class="mt-2 text-fg-danger-dark">{{ actError }}</p>

                                <div v-else-if="actResult" class="mt-2 text-fg-positive-dark">
                                    ✓ {{ actResult.summary }}
                                </div>

                                <button
                                    v-else
                                    class="mt-2 rounded bg-fg-main-blue px-3 py-1.5 text-xs font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                                    :disabled="acting"
                                    @click="doActionItem"
                                >
                                    {{ acting ? 'Doing it…' : 'Yes, do this' }}
                                </button>
                            </div>
                        </div>

                        <div v-else-if="agentResult?.action === 'draft'" class="mt-2 rounded bg-fg-positive-15 p-3 text-sm text-fg-positive-dark">
                            <p class="font-medium">Drafted below from: {{ agentResult.category?.replaceAll('_', ' ') }}</p>
                            <ul v-if="agentResult.data_points?.length" class="mt-1 list-inside list-disc">
                                <li v-for="(point, i) in agentResult.data_points" :key="i">{{ point }}</li>
                            </ul>
                            <p class="mt-1 text-xs">Review before sending — nothing goes out until you hit Send.</p>
                        </div>

                        <p v-else class="mt-2 text-sm text-fg-light-grey">
                            Pulls real numbers for straightforward lookups. Anything needing judgement,
                            involving a dispute, or that reads as personal gets flagged for you instead.
                        </p>
                    </div>

                    <div class="rounded border border-fg-muted-grey bg-white p-4">
                        <label class="mb-1 block text-sm font-medium">
                            {{ selected.reply_body ? 'Send a new reply (replaces the old one)' : 'Reply' }}
                        </label>
                        <textarea
                            v-model="draft"
                            rows="6"
                            class="w-full rounded border border-fg-muted-grey p-2 text-sm"
                            placeholder="Write your reply…"
                        ></textarea>
                        <button
                            class="mt-2 rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                            :disabled="sending || !draft.trim()"
                            @click="sendReply"
                        >
                            {{ sending ? 'Sending…' : 'Send' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
