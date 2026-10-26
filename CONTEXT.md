# WorkFlow

WorkFlow describes multi-step processes and their progress for a subject. Its language separates a reusable definition from each occurrence of that process.

## Language

**Flow Definition**:
An immutable, published description of a flow's steps and their relationships. Each Flow Run uses one specific version.

**Flow Run**:
One distinct occurrence of a Flow Definition for a Subject. It has its own identity, even when the same subject runs the same definition again.

**Subject**:
The domain entity or work item a Flow Run concerns.

**Step**:
A named unit or gate within a Flow Definition whose outcome contributes to the Flow Run's progress.

**Flow State**:
The current progress and step outcomes of one Flow Run.

**Step Attempt**:
One invocation of a Step's operation within a Flow Run. A retry is a new attempt of that same step.

**Ready Set**:
All Steps eligible to begin from the same Flow State snapshot. It describes eligibility, not a claim or a promise that the Steps will execute concurrently.

**Child Run**:
A distinct Flow Run started by a parent Step. It has its own identity and state; the parent may either wait for its outcome or continue after dispatch.

**Deferred Completion**:
An external outcome for a waiting Step Attempt, identified by its Flow Run, Step, attempt number, and event identity.

**Cancellation**:
A decision to stop future progress in a Flow Run without undoing outcomes already produced.
