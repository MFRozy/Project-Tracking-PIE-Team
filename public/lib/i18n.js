'use strict';

(function (root) {
  const phrases = {
    'Improvement Request List': {
      en: 'Improvement Request List',
      ko: '개선 요청 목록'
    },
    'Request aktif': {
      en: 'Active requests',
      ko: '진행 중인 요청'
    },
    'Semua status (termasuk arsip)': {
      en: 'All statuses (including archive)',
      ko: '전체 상태 (보관 항목 포함)'
    },
    'Arsip request': {
      en: 'Archived requests',
      ko: '보관된 요청'
    },
    'Tandai selesai': {
      en: 'Mark as completed',
      ko: '완료로 표시'
    },
    'Tolak usulan': {
      en: 'Reject request',
      ko: '요청 거부'
    },
    'Selesai': {
      en: 'Completed',
      ko: '완료'
    },
    'Ditolak': {
      en: 'Rejected',
      ko: '거부됨'
    },
    'PIC Utama *': {
      en: 'Primary PIC *',
      ko: '주 담당자 *'
    },
    'Project PI': {
      en: 'PI Project',
      ko: 'PI 프로젝트'
    }
  };

  root.PITranslator = {
    translate(value, language) {
      const entry = phrases[String(value || '').trim()];
      return entry ? entry[language] || null : null;
    }
  };
})(globalThis);
