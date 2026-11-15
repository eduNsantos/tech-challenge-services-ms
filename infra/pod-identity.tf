locals {
  # Os consumers e publishers do MS (lambda.tf) e da aplicação
  # (tech-challenge-kubernetes/app-lambda) rodam em Lambda, com roles próprias.
  # Nenhum workload do EKS acessa as filas; o mapa fica vazio.
  pod_identity_workloads = {}
}

resource "aws_eks_addon" "pod_identity_agent" {
  count = var.enable_pod_identity ? 1 : 0

  cluster_name = data.aws_eks_node_group.main.cluster_name
  addon_name   = "eks-pod-identity-agent"
}

resource "aws_iam_role" "pod_identity" {
  for_each = var.enable_pod_identity ? local.pod_identity_workloads : {}

  name = "${var.project_name}-${each.key}-pod-identity"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect = "Allow"
      Principal = {
        Service = "pods.eks.amazonaws.com"
      }
      Action = ["sts:AssumeRole", "sts:TagSession"]
      Condition = {
        StringEquals = {
          "aws:RequestTag/eks-cluster-name"           = data.aws_eks_node_group.main.cluster_name
          "aws:RequestTag/kubernetes-namespace"       = each.value.namespace
          "aws:RequestTag/kubernetes-service-account" = each.value.service_account
        }
      }
    }]
  })
}

resource "aws_iam_role_policy" "pod_identity_sqs" {
  for_each = var.enable_pod_identity ? local.pod_identity_workloads : {}

  name = "${var.project_name}-${each.key}-sqs"
  role = aws_iam_role.pod_identity[each.key].id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = each.value.actions
      Resource = each.value.queue_arn
    }]
  })
}

resource "aws_eks_pod_identity_association" "workload" {
  for_each = var.enable_pod_identity ? local.pod_identity_workloads : {}

  cluster_name    = data.aws_eks_node_group.main.cluster_name
  namespace       = each.value.namespace
  service_account = each.value.service_account
  role_arn        = aws_iam_role.pod_identity[each.key].arn

  depends_on = [
    aws_eks_addon.pod_identity_agent,
    aws_iam_role_policy.pod_identity_sqs,
  ]
}
