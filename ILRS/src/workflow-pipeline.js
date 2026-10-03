// ILRS — Task/reminder workflow stages (renderer)
(function () {
  const CATEGORY_COLORS = {
    open: { bg: 'rgba(59, 130, 246, 0.16)', border: '#3b82f6' },
    active: { bg: 'rgba(34, 197, 94, 0.16)', border: '#22c55e' },
    hold: { bg: 'rgba(234, 179, 8, 0.16)', border: '#eab308' },
    closed: { bg: 'rgba(100, 116, 139, 0.16)', border: '#64748b' },
    general: { bg: 'rgba(148, 163, 184, 0.16)', border: '#94a3b8' },
  };

  let taskStages = [];
  let reminderStages = [];

  function setStages(entityType, stages) {
    if (entityType === 'task') taskStages = stages || [];
    if (entityType === 'reminder') reminderStages = stages || [];
  }

  function getStages(entityType) {
    return entityType === 'task' ? taskStages : reminderStages;
  }

  function getStage(entityType, key) {
    const stages = getStages(entityType);
    return stages.find((s) => s.key === key) || stages[0];
  }

  function stageClass(entityType, key) {
    const cat = getStage(entityType, key)?.category || 'general';
    return `wf-stage-cat-${cat}`;
  }

  function stageDisplay(entityType, key) {
    return getStage(entityType, key)?.display || key || '—';
  }

  window.ILRSWorkflowPipeline = {
    CATEGORY_COLORS,
    setStages,
    getStages,
    getStage,
    stageClass,
    stageDisplay,
  };
})();
